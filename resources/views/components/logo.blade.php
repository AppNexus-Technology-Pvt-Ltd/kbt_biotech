@props(['size' => 'md', 'stacked' => false, 'light' => false])
<span {{ $attributes->class(['logo', 'logo--'.$size, 'logo--stacked' => $stacked, 'logo--light' => $light]) }} role="img" aria-label="KBT RUVIA BioTech">
    <span aria-hidden="true">KBT RUVIA</span>
    <span class="logo__bio" aria-hidden="true">
        <svg class="logo__pulse" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12h4l2.5-6 4 12 3-9 1.5 3H22"/></svg>BioTech
    </span>
</span>
