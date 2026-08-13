<?php

namespace App\Filament\Vendor\Pages\Auth;

use App\Access\VendorAccess;
use App\Models\Survey;
use App\Models\VendorUser;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Url;

class SurveyAccess extends SimplePage implements HasForms
{
    use InteractsWithForms;
    use WithRateLimiting;

    protected string $view = 'filament.vendor.pages.auth.survey-access';

    protected static string $layout = 'filament-panels::components.layout.simple';

    #[Url]
    public ?int $survey = null;

    #[Url]
    public ?string $email = null;

    public ?Survey $surveyRecord = null;

    public ?VendorUser $existingUser = null;

    public string $mode = 'login'; // 'login', 'register', 'set-password'

    public ?array $loginData = [];

    public ?array $registerData = [];

    public ?array $passwordData = [];

    public function mount(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('vendor'));

        if ($this->survey) {
            $this->surveyRecord = Survey::with('vendor')->find($this->survey);
        }

        if (! $this->surveyRecord || ! $this->surveyRecord->vendor_id) {
            abort(404, 'Survey not found.');
        }

        $vendorAccess = app(VendorAccess::class);
        $vendorAccess->establishClaimFromRequest(request(), $this->surveyRecord);

        if (Auth::guard('vendor')->check()) {
            $vendorUser = Auth::guard('vendor')->user();

            if ($vendorAccess->mayOpenSurvey($vendorUser, $this->surveyRecord)) {
                $this->redirectToSurvey();

                return;
            }

            Auth::guard('vendor')->logout();
        }

        // Pre-fill email from URL or survey respondent
        $prefillEmail = $this->email ?? $this->surveyRecord->respondent_email;

        // Check if a vendor user exists for this email (not scoped to survey's vendor,
        // since users can be assigned surveys for vendors they don't belong to)
        if ($prefillEmail) {
            $this->existingUser = VendorUser::where('email', $prefillEmail)->first();

            if ($this->existingUser) {
                $this->loginData['email'] = $prefillEmail;

                if ($this->existingUser->hasPassword()) {
                    $this->mode = 'login';
                } else {
                    $this->mode = 'set-password';
                    $this->passwordData['email'] = $prefillEmail;
                }
            } else {
                $this->mode = 'register';
                $this->registerData['email'] = $prefillEmail;
            }
        }
    }

    public function getTitle(): string|Htmlable
    {
        return match ($this->mode) {
            'register' => 'Create Your Account',
            'set-password' => 'Set Your Password',
            default => 'Sign In to Continue',
        };
    }

    public function getSubheading(): ?string
    {
        $surveyTitle = $this->surveyRecord?->display_title ?? 'Survey';

        return "Access survey: {$surveyTitle}";
    }

    protected function getForms(): array
    {
        return [
            'loginForm',
            'registerForm',
            'setPasswordForm',
        ];
    }

    public function loginForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Placeholder::make('vendor_info')
                    ->label('')
                    ->content(fn () => 'Sign in with your vendor portal credentials to access this survey.'),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->autocomplete('email'),
                TextInput::make('password')
                    ->label('Password')
                    ->password()
                    ->required()
                    ->autocomplete('current-password'),
            ])
            ->statePath('loginData');
    }

    public function registerForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Placeholder::make('vendor_info')
                    ->label('')
                    ->content(fn () => "Creating an account for {$this->surveyRecord->vendor->name}"),
                TextInput::make('name')
                    ->label('Full Name')
                    ->required()
                    ->maxLength(255)
                    ->autocomplete('name'),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->autocomplete('email')
                    ->unique(table: 'vendor_users', column: 'email', ignoreRecord: true)
                    ->validationMessages([
                        'unique' => 'This email is already registered. Please sign in instead.',
                    ]),
                TextInput::make('password')
                    ->label('Password')
                    ->password()
                    ->required()
                    ->rule(Password::default())
                    ->autocomplete('new-password'),
                TextInput::make('password_confirmation')
                    ->label('Confirm Password')
                    ->password()
                    ->required()
                    ->same('password')
                    ->autocomplete('new-password'),
            ])
            ->statePath('registerData');
    }

    public function setPasswordForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Placeholder::make('welcome')
                    ->label('')
                    ->content(fn () => 'Welcome! Please set a password for your account to continue.'),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->disabled()
                    ->dehydrated(),
                TextInput::make('password')
                    ->label('Password')
                    ->password()
                    ->required()
                    ->rule(Password::default())
                    ->autocomplete('new-password'),
                TextInput::make('password_confirmation')
                    ->label('Confirm Password')
                    ->password()
                    ->required()
                    ->same('password')
                    ->autocomplete('new-password'),
            ])
            ->statePath('passwordData');
    }

    public function login(): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            Notification::make()
                ->title('Too many attempts')
                ->body("Please wait {$exception->secondsUntilAvailable} seconds before trying again.")
                ->danger()
                ->send();

            return;
        }

        $data = $this->loginForm->getState();

        // Find user by email (not scoped to vendor)
        $user = VendorUser::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            Notification::make()
                ->title('Invalid credentials')
                ->body('The email or password you entered is incorrect.')
                ->danger()
                ->send();

            return;
        }

        try {
            app(VendorAccess::class)->login($user, $this->surveyRecord);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            Notification::make()
                ->title('Access denied')
                ->body('You do not have permission to access this survey.')
                ->danger()
                ->send();

            return;
        }

        $this->redirectToSurvey();
    }

    public function register(): void
    {
        try {
            $this->rateLimit(3);
        } catch (TooManyRequestsException $exception) {
            Notification::make()
                ->title('Too many attempts')
                ->body("Please wait {$exception->secondsUntilAvailable} seconds before trying again.")
                ->danger()
                ->send();

            return;
        }

        $data = $this->registerForm->getState();

        // Check if user already exists for this vendor
        $existingUserForVendor = VendorUser::where('vendor_id', $this->surveyRecord->vendor_id)
            ->where('email', $data['email'])
            ->first();

        if ($existingUserForVendor) {
            Notification::make()
                ->title('Account already exists')
                ->body('An account with this email already exists. Please sign in instead.')
                ->warning()
                ->send();

            $this->mode = 'login';
            $this->loginData['email'] = $data['email'];

            return;
        }

        // Check if email exists for any vendor (database has unique constraint on email)
        $existingUserGlobal = VendorUser::where('email', $data['email'])->first();

        if ($existingUserGlobal) {
            Notification::make()
                ->title('Email already registered')
                ->body('This email is already registered. Please sign in with your existing account or use a different email address.')
                ->warning()
                ->send();

            $this->mode = 'login';
            $this->loginData['email'] = $data['email'];

            return;
        }

        try {
            $user = app(VendorAccess::class)->register(
                $this->surveyRecord,
                $data['name'],
                $data['email'],
                $data['password'],
            );
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            Notification::make()
                ->title('Unable to create account')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Account created')
            ->body('Your account has been created successfully.')
            ->success()
            ->send();

        $this->loginUser($user);
    }

    public function setPassword(): void
    {
        $data = $this->setPasswordForm->getState();

        if (! $this->existingUser) {
            Notification::make()
                ->title('Error')
                ->body('User not found. Please try again.')
                ->danger()
                ->send();

            return;
        }

        try {
            app(VendorAccess::class)->setPassword(
                $this->surveyRecord,
                $this->existingUser,
                $data['password'],
            );
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            Notification::make()
                ->title('Error')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Password set')
            ->body('Your password has been set successfully.')
            ->success()
            ->send();

        $this->loginUser($this->existingUser);
    }

    protected function loginUser(VendorUser $user): void
    {
        app(VendorAccess::class)->login($user, $this->surveyRecord);

        $this->redirectToSurvey();
    }

    protected function redirectToSurvey(): void
    {
        $this->redirect(route('filament.vendor.resources.surveys.respond', ['record' => $this->surveyRecord->id]));
    }

    public function switchToLogin(): void
    {
        $this->mode = 'login';
    }

    public function switchToRegister(): void
    {
        $this->mode = 'register';
    }

    public static function getSlug(): string
    {
        return 'survey-access';
    }
}
