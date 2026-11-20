<x-application.settings-section :title="__('Image scanning')"
    :description="__('Scan the images on this server for known vulnerabilities. You can change this later in the security settings of the server.')">
    <x-forms.listbox id="scan_images" :label="__('Scan images for vulnerabilities')"
        :helper="__('By default, scans use up to 1 CPU and 1 GB of memory while they run, and about 1.2 GB of disk, plus temporary space for one image.')"
        :options="[
            ['value' => 'off', 'label' => 'Off'],
            ['value' => 'on', 'label' => 'On'],
        ]" />
</x-application.settings-section>
