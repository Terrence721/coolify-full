<button @disabled($disabled) {{ $attributes->merge(['class' => $defaultClass]) }}
    {{ $attributes->merge(['type' => 'button']) }}>
    {{ $slot }}
</button>
