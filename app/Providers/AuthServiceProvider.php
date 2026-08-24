<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use Aliziodev\LaravelTaxonomy\Models\Taxonomy;
use App\Models\AiSystem;
use App\Models\BusinessService;
use App\Models\ComplianceCase;
use App\Models\ControlTestDefinition;
use App\Models\GovernedModel;
use App\Models\Policy;
use App\Models\PolicyObligation;
use App\Models\PrivacyProcessingActivity;
use App\Models\Survey;
use App\Models\SurveyTemplate;
use App\Models\SystemAuthorizationPackage;
use App\Models\VendorDocument;
use App\Policies\AiSystemPolicy;
use App\Policies\BusinessServicePolicy;
use App\Policies\ComplianceCasePolicy;
use App\Policies\ControlTestDefinitionPolicy;
use App\Policies\GovernedModelPolicy;
use App\Policies\PermissionPolicy;
use App\Policies\PolicyObligationPolicy;
use App\Policies\PolicyPolicy;
use App\Policies\PrivacyProcessingActivityPolicy;
use App\Policies\RolePolicy;
use App\Policies\SurveyPolicy;
use App\Policies\SurveyTemplatePolicy;
use App\Policies\SystemAuthorizationPackagePolicy;
use App\Policies\TaxonomyPolicy;
use App\Policies\VendorDocumentPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Laravel\Passport\Passport;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AuthServiceProvider extends ServiceProvider
{
    protected string $redirectTo = '/app/login';

    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Role::class => RolePolicy::class,
        Permission::class => PermissionPolicy::class,
        Taxonomy::class => TaxonomyPolicy::class,
        Policy::class => PolicyPolicy::class,
        PolicyObligation::class => PolicyObligationPolicy::class,
        ControlTestDefinition::class => ControlTestDefinitionPolicy::class,
        BusinessService::class => BusinessServicePolicy::class,
        ComplianceCase::class => ComplianceCasePolicy::class,
        GovernedModel::class => GovernedModelPolicy::class,
        SystemAuthorizationPackage::class => SystemAuthorizationPackagePolicy::class,
        PrivacyProcessingActivity::class => PrivacyProcessingActivityPolicy::class,
        AiSystem::class => AiSystemPolicy::class,
        Survey::class => SurveyPolicy::class,
        SurveyTemplate::class => SurveyTemplatePolicy::class,
        VendorDocument::class => VendorDocumentPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // Configure Passport OAuth scopes
        Passport::tokensCan([
            'mcp:use' => 'Use MCP server',
        ]);

        // Set token expiration times
        Passport::tokensExpireIn(now()->addMinutes(60));
        Passport::refreshTokensExpireIn(now()->addDays(7));
        Passport::personalAccessTokensExpireIn(now()->addMonths(6));

        // UUIDs are configured via config/passport.php 'client_uuids' => true

        // Use MCP authorization view for OAuth consent screen
        Passport::authorizationView(function ($parameters) {
            return view('mcp.authorize', $parameters);
        });
    }
}
