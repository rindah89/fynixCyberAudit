# Media Proxy for Private Cloud Storage

## Overview

Fynix Cyber Audit implements a Laravel-based media proxy to serve private cloud storage files (S3, DigitalOcean Spaces) through the application rather than directly from cloud storage. This enables browser-based rich text editors to display images from private storage without CORS issues or requiring public bucket policies.

## Problem Statement

### The Challenge

Modern rich text editors (Filament RichEditor, Tiptap, Quill) require JavaScript to load images in the browser for editing, preview, and feature functionality (dimension checks, resize handles, etc.). When storing files in private cloud storage:

1. **Direct S3 URLs are blocked** - Private files return 403 Forbidden to browser requests
2. **Signed URLs have limitations**:
   - Short expiration times (typically 5 minutes)
   - CORS preflight requests may fail
   - Timing issues with immediate browser access after upload
   - Browser dimension checks fail with CORS restrictions
3. **Security requirement** - Files must remain private (not publicly accessible)

### Why This Happens

- **Browser Same-Origin Policy**: JavaScript cannot fetch images from different origins (S3 domain) without proper CORS
- **S3 Private Files**: Block anonymous access by design
- **CORS Complexity**: Even with CORS configured on S3, timing and preflight issues occur
- **Editor Requirements**: Rich text editors need immediate, reliable image access for:
  - Real-time preview
  - Resize handles
  - Dimension detection
  - Image property dialogs

## Solution: Laravel Media Proxy

Instead of serving files directly from S3, route image requests through Laravel:

```
Browser → Laravel (/media/path) → S3 (authenticated) → Browser
```

### Benefits

* ✅ **No CORS Issues** - Same-origin requests (browser → your domain)
* ✅ **Private Storage** - Files remain private in S3, no public bucket policies needed
* ✅ **Works with Any Editor** - Filament RichEditor, Tiptap, Quill, etc.
* ✅ **Works with Any Cloud** - S3, DigitalOcean Spaces, any S3-compatible storage
* ✅ **Authentication** - Laravel middleware controls access
* ✅ **Efficient** - Streams files directly, doesn't load into memory
* ✅ **Cached** - Browser caches files for performance

## Implementation

### 1. Media Proxy Controller

**File**: `app/Http/Controllers/MediaProxyController.php`

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaProxyController extends Controller
{
    /**
     * Serve private media files through Laravel proxy
     * This allows browser-based editors to display private S3 images
     */
    public function show(Request $request, string $path): StreamedResponse
    {
        // Get the storage disk (defaults to configured disk)
        $disk = Storage::disk(config('filesystems.default'));

        // Decode the path (in case it was URL encoded)
        $filePath = urldecode($path);

        // Security: Prevent directory traversal attacks
        if (str_contains($filePath, '..') || str_starts_with($filePath, '/')) {
            abort(403, 'Invalid file path');
        }

        // Check if file exists
        if (! $disk->exists($filePath)) {
            abort(404, 'File not found');
        }

        // Get file metadata
        try {
            $mimeType = $disk->mimeType($filePath);
            $size = $disk->size($filePath);
        } catch (\Exception $e) {
            abort(500, 'Unable to retrieve file metadata');
        }

        // Stream the file from S3 (efficient for large files)
        return new StreamedResponse(function () use ($disk, $filePath) {
            $stream = $disk->readStream($filePath);
            if ($stream === false) {
                abort(500, 'Unable to read file');
            }
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mimeType,
            'Content-Length' => $size,
            'Cache-Control' => 'public, max-age=31536000', // Cache for 1 year
            'Content-Disposition' => 'inline', // Display in browser, not download
        ]);
    }
}
```

**Key Features**:
- **Security**: Prevents directory traversal (`../` attacks)
- **Streaming**: Uses `StreamedResponse` for efficient memory usage
- **Caching**: 1-year browser cache for performance
- **Error Handling**: Proper 403/404/500 responses

### 2. Route Configuration

**File**: `routes/web.php`

```php
Route::middleware(['auth'])->group(function () {
    // Media proxy route for serving private S3/cloud storage files
    Route::get('/media/{path}', [\App\Http\Controllers\MediaProxyController::class, 'show'])
        ->where('path', '.*')
        ->name('media.show');
});
```

**Key Points**:
- Protected by `auth` middleware (only logged-in users)
- Wildcard path matcher (`.*`) to handle nested directories
- Named route for easy URL generation

### 3. Filesystem Configuration

**File**: `config/filesystems.php`

Configure S3 and DigitalOcean disks to use the proxy URL:

```php
's3' => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('AWS_BUCKET'),
    'url' => env('AWS_URL', env('APP_URL').'/media'),  // Proxy URL
    'endpoint' => env('AWS_ENDPOINT'),
    'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
    'throw' => false,
    'visibility' => 'private',
],

'digitalocean' => [
    'driver' => 's3',
    'key' => env('DO_SPACES_KEY'),
    'secret' => env('DO_SPACES_SECRET'),
    'region' => env('DO_SPACES_REGION', 'us-east-1'),
    'bucket' => env('DO_SPACES_BUCKET'),
    'url' => env('DO_SPACES_URL', env('APP_URL').'/media'),  // Proxy URL
    'endpoint' => env('DO_SPACES_ENDPOINT'),
    'use_path_style_endpoint' => env('DO_SPACES_USE_PATH_STYLE', true),
    'throw' => false,
],
```

**How It Works**:
- When Laravel generates URLs via `Storage::disk('s3')->url($path)`
- It uses the `url` config instead of direct S3 URLs
- Results in: `https://yourdomain.com/media/ssp-uploads/image.png`
- Instead of: `https://bucket.s3.amazonaws.com/ssp-uploads/image.png`

### 4. Rich Text Editor Configuration

**File**: `app/Filament/Resources/ProgramResource.php`

```php
RichEditor::make('description')
    ->label(__('programs.form.description'))
    ->fileAttachmentsDisk(setting('storage.driver', 'private'))
    ->fileAttachmentsVisibility('private')
    ->fileAttachmentsDirectory('ssp-uploads')
    ->columnSpanFull(),
```

**No Special Configuration Needed**:
- The editor uses Laravel's `Storage::url()` which automatically uses proxy URLs
- Works with Filament RichEditor or any other editor
- Images upload to S3 (private) and display via proxy

## Request Flow

### Upload Process
1. User uploads image via rich text editor
2. Livewire/Filament handles upload
3. File stored to S3/Spaces with private visibility
4. Laravel generates proxy URL: `https://yourdomain.com/media/ssp-uploads/abc123.png`
5. Editor receives proxy URL and displays image

### Display Process
1. Browser requests: `GET https://yourdomain.com/media/ssp-uploads/abc123.png`
2. Laravel route matches `/media/{path}` → `MediaProxyController::show()`
3. Controller authenticates user (middleware)
4. Controller fetches file from S3 using AWS SDK
5. Controller streams file to browser with proper headers
6. Browser displays image (and caches for 1 year)

### PDF Generation Process
1. PDF generation code (e.g., SSP report) includes images via proxy URLs
2. `PdfHelper::convertImagesToBase64()` converts HTML images
3. Helper extracts storage path from proxy URL
4. Fetches file directly from S3 Storage disk
5. Converts to base64 data URI for DomPDF
6. PDF includes embedded images

## Security Considerations

### Authentication
- Proxy route protected by `auth` middleware
- Only logged-in users can access media files
- Can add additional authorization checks if needed (role-based, tenant-based)

### Path Traversal Prevention
```php
if (str_contains($filePath, '..') || str_starts_with($filePath, '/')) {
    abort(403, 'Invalid file path');
}
```
Prevents attacks like: `/media/../../../etc/passwd`

### Private Storage
- Files stored with `private` visibility in S3
- No public bucket policies required
- S3 blocks all direct anonymous access
- Only Laravel application can access via AWS credentials

### Cache Headers
```php
'Cache-Control' => 'public, max-age=31536000'
```
- Files cached by browser for 1 year
- Reduces server load and S3 API calls
- "public" means cache can store (still requires auth to first fetch)

## Performance Considerations

### Streaming vs Loading
```php
return new StreamedResponse(function () use ($disk, $filePath) {
    $stream = $disk->readStream($filePath);
    fpassthru($stream);
});
```
- Uses `readStream()` instead of `get()`
- Doesn't load entire file into PHP memory
- Efficient for large images/videos
- Streams directly from S3 → PHP → Browser

### Browser Caching
- 1-year cache means browser only requests once
- Subsequent page loads serve from browser cache
- No additional server/S3 requests

### S3 API Calls
- Each proxy request = 1 S3 GET request
- Cached by browser, so minimal repeated requests
- Consider adding Laravel cache layer if needed:
  ```php
  Cache::remember("media.{$filePath}", now()->addHours(24), fn() => $disk->get($filePath))
  ```

## Troubleshooting

### Images Not Displaying
1. **Check authentication**: Ensure user is logged in
2. **Check file exists**: Verify file uploaded to correct S3 bucket/path
3. **Check S3 credentials**: Ensure `.env` has correct AWS credentials
4. **Check route**: `php artisan route:list | grep media`
5. **Check logs**: `tail -f storage/logs/laravel.log`

### 403 Forbidden Errors
- User not authenticated → Check `auth` middleware
- Path traversal detected → Check file path format
- S3 credentials invalid → Test with `php artisan tinker`

### 404 Not Found Errors
- File doesn't exist in S3 → Check bucket and path
- Wrong disk configured → Verify `FILESYSTEM_DISK` in `.env`
- Path mismatch → Check URL encoding/decoding

### Performance Issues
- Add Laravel caching layer for frequently accessed files
- Consider CDN in front of proxy for public-facing content
- Monitor S3 API costs with high traffic

## Alternative Approaches Considered

### 1. Public S3 Bucket
❌ **Rejected**: Files would be accessible to anyone with URL
❌ **Security Risk**: No authentication/authorization

### 2. Signed URLs
❌ **Rejected**: CORS issues with browser JavaScript
❌ **Timing Issues**: URLs expire, editor checks fail
❌ **Complexity**: Requires S3 CORS configuration

### 3. CloudFront with Signed Cookies
✅ **Valid Alternative**: Could work but adds complexity
❌ **Cost**: Additional AWS service
❌ **Setup**: Requires CloudFront distribution configuration

### 4. Laravel Proxy (Chosen)
✅ **Simple**: Single controller, single route
✅ **Secure**: Laravel authentication/authorization
✅ **Flexible**: Works with any cloud storage
✅ **No CORS**: Same-origin requests
✅ **Cost**: No additional services

## Testing

### Manual Testing
1. Upload image in rich text editor
2. Verify image displays in editor
3. Save and reload form → image persists
4. Check browser Network tab → requests to `/media/...` return 200
5. Generate PDF → verify images appear

### Automated Testing
```php
// Test media proxy authentication
public function test_media_proxy_requires_authentication()
{
    $response = $this->get('/media/ssp-uploads/test.png');
    $response->assertRedirect('/login');
}

// Test media proxy serves file
public function test_media_proxy_serves_existing_file()
{
    Storage::fake('s3');
    Storage::disk('s3')->put('ssp-uploads/test.png', 'fake-image-content');

    $response = $this->actingAs($user)->get('/media/ssp-uploads/test.png');
    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/png');
}

// Test media proxy prevents directory traversal
public function test_media_proxy_prevents_directory_traversal()
{
    $response = $this->actingAs($user)->get('/media/../../../etc/passwd');
    $response->assertForbidden();
}
```

## Maintenance

### Monitoring
- Monitor S3 API costs (GET requests)
- Monitor Laravel response times for media requests
- Monitor disk space if adding Laravel cache layer

### Updates
- Keep AWS SDK updated (`composer update aws/aws-sdk-php`)
- Review security advisories for Laravel Storage
- Consider adding rate limiting if needed

### Scaling
- Add Redis/Memcached caching if S3 API costs increase
- Consider moving to CloudFront if serving high volumes
- Monitor Laravel server load with image streaming

## Conclusion

The media proxy solution provides a simple, secure, and performant way to serve private cloud storage files to browser-based rich text editors. By routing requests through Laravel, we maintain security (authentication/authorization) while avoiding CORS complexity and ensuring compatibility with all editor types.

**Key Takeaway**: When browser JavaScript needs to access private cloud storage, proxying through your application server is often simpler and more secure than trying to configure cloud storage for direct browser access.

## Related Files

- **Controller**: `app/Http/Controllers/MediaProxyController.php`
- **Routes**: `routes/web.php`
- **Config**: `config/filesystems.php`
- **Editor Usage**: `app/Filament/Resources/ProgramResource.php`
- **PDF Helper**: `app/Http/Controllers/PdfHelper.php` (converts proxy URLs to base64)

## References

- [Laravel Storage Documentation](https://laravel.com/docs/11.x/filesystem)
- [AWS S3 PHP SDK](https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/s3-stream-wrapper.html)
- [Filament File Attachments](https://filamentphp.com/docs/3.x/forms/fields/rich-editor#file-attachments)
- [Symfony StreamedResponse](https://symfony.com/doc/current/components/http_foundation.html#streaming-a-response)
