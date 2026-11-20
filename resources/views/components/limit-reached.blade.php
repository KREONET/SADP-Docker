<div class="flex flex-col items-center justify-center h-32">
    <span class="text-xl font-bold dark:text-white">{{ __('You have reached the limit of :name you can create.', ['name' => $name]) }}</span>
    <span>{!! __('Please <a class="dark:text-white underline" :navigate href=":url">upgrade your subscription</a> to create more :name.', ['navigate' => wireNavigate(), 'url' => e(route('subscription.show')), 'name' => e($name)]) !!}</span>
</div>
