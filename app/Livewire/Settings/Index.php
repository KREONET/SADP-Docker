<?php

namespace App\Livewire\Settings;

use App\Models\InstanceSettings;
use App\Models\Server;
use App\Services\BrandingLogoStorageService;
use App\Support\InterfaceLocale;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

class Index extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    public InstanceSettings $settings;

    public ?Server $server = null;

    #[Validate('nullable|string|max:255|url')]
    public ?string $fqdn = null;

    #[Validate('boolean')]
    public bool $is_dashboard_force_https_enabled = true;

    #[Validate('required|integer|min:1025|max:65535')]
    public int $public_port_min;

    #[Validate('required|integer|min:1025|max:65535')]
    public int $public_port_max;

    #[Validate('nullable|string|max:255')]
    public ?string $instance_name = null;

    #[Validate('nullable|ipv4')]
    public ?string $public_ipv4 = null;

    #[Validate('nullable|ipv6')]
    public ?string $public_ipv6 = null;

    #[Validate('required|string|timezone')]
    public string $instance_timezone;

    public string $default_locale = InterfaceLocale::FALLBACK;

    #[Validate(['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$/'])]
    public ?string $dev_helper_version = null;

    /** Site name shown in titles, the navbar and sign-in pages; saved by saveBranding(). */
    public ?string $brand_name = null;

    /** Pending logo upload; stored by uploadLogo(). */
    public $logo;

    /** IP addresses and CIDR ranges allowed to open "internal network only" applications; saved by saveInternalNetworks(). */
    public ?string $internal_network_cidrs = null;

    public array $domainConflicts = [];

    public bool $showDomainConflictModal = false;

    public bool $forceSaveDomains = false;

    protected function messages(): array
    {
        return [
            'fqdn.url' => __('Invalid instance URL.'),
            'fqdn.max' => __('URL must not exceed 255 characters.'),
            'dev_helper_version.regex' => __('Dev helper version must match Docker tag format (alphanumeric, _, ., -; first char cannot be . or -).'),
        ];
    }

    public function render()
    {
        return view('livewire.settings.index');
    }

    public function mount()
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }
        $this->settings = instanceSettings();
        if (! isCloud()) {
            $this->server = Server::findOrFail(0);
        }
        $this->fqdn = $this->settings->fqdn;
        $this->is_dashboard_force_https_enabled = $this->settings->is_dashboard_force_https_enabled;
        $this->public_port_min = $this->settings->public_port_min;
        $this->public_port_max = $this->settings->public_port_max;
        $this->instance_name = $this->settings->instance_name;
        $this->public_ipv4 = $this->settings->public_ipv4;
        $this->public_ipv6 = $this->settings->public_ipv6;
        $this->instance_timezone = $this->settings->instance_timezone;
        $this->default_locale = InterfaceLocale::instanceDefault();
        $this->dev_helper_version = $this->settings->dev_helper_version;
        $this->brand_name = $this->settings->brand_name;
        $this->internal_network_cidrs = $this->settings->internal_network_cidrs;
    }

    public function saveInternalNetworks(): void
    {
        $this->authorize('update', $this->settings);
        $this->validate(['internal_network_cidrs' => ['nullable', 'string', 'max:5000']]);

        $ranges = parseNetworkRanges($this->internal_network_cidrs);
        $invalid = array_values(array_filter($ranges, fn (string $range) => ! isValidIpOrCidr($range)));
        if ($invalid !== []) {
            $this->addError('internal_network_cidrs', __('Not an IP address or CIDR range: :ranges', ['ranges' => implode(', ', $invalid)]));

            return;
        }

        $this->settings->update(['internal_network_cidrs' => $ranges === [] ? null : implode("\n", $ranges)]);
        $this->internal_network_cidrs = $this->settings->internal_network_cidrs;
        auditLog('ui.instance.internal_network_ranges_updated', ['ranges' => $ranges]);
        $this->dispatch('success', __('Internal network ranges saved. Restart internal network only applications to apply them.'));
    }

    public function saveBranding()
    {
        $this->authorize('update', $this->settings);
        $this->validate([
            'brand_name' => ['nullable', 'string', 'max:60', 'regex:/^[^<>]*$/'],
        ]);

        $brandName = trim((string) $this->brand_name);
        $this->settings->update(['brand_name' => $brandName !== '' ? $brandName : null]);
        auditLog('ui.instance.brand_name_updated', ['brand_name' => $this->settings->brand_name]);

        // Reload so the persisted sidebar and the page head show the new name.
        return $this->redirect(route('settings.index'));
    }

    public function uploadLogo(BrandingLogoStorageService $logoStorage)
    {
        $this->authorize('update', $this->settings);
        $this->validate([
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp,svg', 'max:1024'],
        ]);

        if ($this->logo->guessExtension() === 'svg'
            && ! BrandingLogoStorageService::isSafeSvg((string) file_get_contents($this->logo->getRealPath()))) {
            $this->addError('logo', __('The SVG logo must not contain scripts or event handlers.'));

            return null;
        }

        $logoStorage->store($this->settings, $this->logo);
        $this->reset('logo');
        auditLog('ui.instance.brand_logo_updated');

        return $this->redirect(route('settings.index'));
    }

    public function removeLogo(BrandingLogoStorageService $logoStorage)
    {
        $this->authorize('update', $this->settings);

        $logoStorage->delete($this->settings);
        auditLog('ui.instance.brand_logo_removed');

        return $this->redirect(route('settings.index'));
    }

    #[Computed]
    public function timezones(): array
    {
        return collect(timezone_identifiers_list())
            ->sort()
            ->values()
            ->toArray();
    }

    public function instantSave($isSave = true)
    {
        $this->authorize('update', $this->settings);
        $this->validate();
        $this->settings->fqdn = $this->fqdn ? trim($this->fqdn) : $this->fqdn;
        $this->settings->is_dashboard_force_https_enabled = $this->is_dashboard_force_https_enabled;
        $this->settings->public_port_min = $this->public_port_min;
        $this->settings->public_port_max = $this->public_port_max;
        $this->settings->instance_name = $this->instance_name;
        $this->settings->public_ipv4 = $this->public_ipv4;
        $this->settings->public_ipv6 = $this->public_ipv6;
        $this->settings->instance_timezone = $this->instance_timezone;
        $this->settings->dev_helper_version = $this->dev_helper_version;
        if ($isSave) {
            $this->settings->save();
            $this->dispatch('success', __('Settings updated!'));
        }
    }

    public function updateDefaultLocale()
    {
        $this->authorize('update', $this->settings);
        $this->validate([
            'default_locale' => ['required', 'in:'.implode(',', array_keys(InterfaceLocale::available()))],
        ]);

        $this->settings->update(['default_locale' => $this->default_locale]);
        auditLog('ui.instance.default_locale_updated', ['locale' => $this->default_locale]);

        // Reload so this page also renders in the new default when the admin follows it.
        return $this->redirect(route('settings.index'));
    }

    public function confirmDomainUsage()
    {
        $this->authorize('update', $this->settings);
        $this->forceSaveDomains = true;
        $this->showDomainConflictModal = false;
        $this->submit();
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->settings);
            $error_show = false;
            $this->resetErrorBag();

            if (! validate_timezone($this->instance_timezone)) {
                $this->instance_timezone = config('app.timezone');
                throw new \Exception(__('Invalid timezone.'));
            } else {
                $this->settings->instance_timezone = $this->instance_timezone;
            }

            if ($this->settings->public_port_min > $this->settings->public_port_max) {
                $this->addError('settings.public_port_min', __('The minimum port must be lower than the maximum port.'));

                return;
            }

            // Trim FQDN to remove leading/trailing whitespace before validation
            if ($this->fqdn) {
                $this->fqdn = trim($this->fqdn);
            }

            $this->validate();

            if ($this->settings->is_dns_validation_enabled && $this->fqdn && $this->server) {
                if (! validateDNSEntry($this->fqdn, $this->server)) {
                    $target = serverDnsTargetIp($this->server) ?? $this->server->ip;
                    $guidance = dnsMismatchGuidanceMessage($target, $target);
                    $this->dispatch('error', __("Validating DNS failed.<br><br>:guidance<br><br>Check this <a target='_blank' class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/dns-configuration'>documentation</a> for further help.", ['guidance' => $guidance]));
                    $error_show = true;
                }
            }
            if ($this->fqdn) {
                if (! $this->forceSaveDomains) {
                    $result = checkDomainUsage(domain: $this->fqdn);
                    if ($result['hasConflicts']) {
                        $this->domainConflicts = $result['conflicts'];
                        $this->showDomainConflictModal = true;

                        return;
                    }
                } else {
                    // Reset the force flag after using it
                    $this->forceSaveDomains = false;
                }
            }

            $this->instantSave(isSave: false);

            $this->settings->save();
            if ($this->server) {
                $this->server->setupDynamicProxyConfiguration();
            }
            if (! $error_show) {
                $this->dispatch('success', __('Instance settings updated successfully!'));
            }
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function buildHelperImage()
    {
        try {
            $this->authorize('update', $this->settings);
            if (! isDev()) {
                $this->dispatch('error', __('Building helper image is only available in development mode.'));

                return;
            }

            if (! $this->server) {
                $this->dispatch('error', __('Server not available.'));

                return;
            }

            $this->validateOnly('dev_helper_version');

            $version = $this->dev_helper_version ?: config('constants.coolify.helper_version');
            if (empty($version)) {
                $this->dispatch('error', __('Please specify a version to build.'));

                return;
            }

            if (! preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$/', (string) $version)) {
                $this->dispatch('error', __('Invalid helper version format.'));

                return;
            }

            $imageRef = escapeshellarg(coolifyHelperImage().":{$version}");
            $buildCommand = "docker build -t {$imageRef} -f docker/coolify-helper/Dockerfile .";

            $activity = remote_process(
                command: [$buildCommand],
                server: $this->server,
                type: 'build-helper-image'
            );

            $this->buildActivityId = $activity->id;
            $this->dispatch('activityMonitor', $activity->id);

            $this->dispatch('success', __('Building coolify-helper::version...', ['version' => $version]));
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }
}
