@props(['light' => false])
<span {{ $attributes->class(['brand', 'brand--light' => $light]) }}>
    <span class="brand-symbol" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></span>
    <span class="brand-word">voca<span class="brand-dot">.</span></span>
</span>
