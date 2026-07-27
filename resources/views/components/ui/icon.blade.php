@props(['name'])

<svg
    aria-hidden="true"
    fill="none"
    viewBox="0 0 24 24"
    stroke-width="1.8"
    stroke="currentColor"
    {{ $attributes->merge(['class' => 'h-4 w-4 shrink-0']) }}
>
    @switch($name)
        @case('export')
        @case('upload')
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M7.5 8.25 12 3.75m0 0 4.5 4.5M12 3.75V15" />
            @break
        @case('edit')
            <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931ZM19.5 7.125 16.875 4.5M18 13.5V19.125A1.875 1.875 0 0 1 16.125 21H4.875A1.875 1.875 0 0 1 3 19.125V7.875A1.875 1.875 0 0 1 4.875 6H10.5" />
            @break
        @case('console')
            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3.75h10.5A2.25 2.25 0 0 1 19.5 6v8.25a2.25 2.25 0 0 1-2.25 2.25H6.75a2.25 2.25 0 0 1-2.25-2.25V6a2.25 2.25 0 0 1 2.25-2.25ZM9 20.25h6M12 16.5v3.75m-2.25-11.5 4.5 2.625-4.5 2.625V8.75Z" />
            @break
        @case('results')
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v18m0-3.75h16.5M7.5 14.25v-3m4.5 3V7.5m4.5 6.75v-6" />
            @break
        @case('chevron-down')
            <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
            @break
        @case('more')
            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm6 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm6 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
            @break
        @case('copy')
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v2.625c0 .621-.504 1.125-1.125 1.125h-9.75A1.125 1.125 0 0 1 3.75 19.875v-9.75C3.75 9.504 4.254 9 4.875 9H7.5m8.25 8.25h3.375c.621 0 1.125-.504 1.125-1.125v-9.75c0-.621-.504-1.125-1.125-1.125h-9.75c-.621 0-1.125.504-1.125 1.125V9m7.5 8.25H8.625A1.125 1.125 0 0 1 7.5 16.125V9" />
            @break
        @case('archive')
            <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5v10.125c0 1.036-.84 1.875-1.875 1.875H5.625a1.875 1.875 0 0 1-1.875-1.875V7.5m16.5 0H3.75m16.5 0V5.625A1.875 1.875 0 0 0 18.375 3.75H5.625A1.875 1.875 0 0 0 3.75 5.625V7.5m5.25 4.5h6" />
            @break
        @case('activate')
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992V4.356m-.97 5.962A9 9 0 1 0 21 12.75" />
            @break
        @case('import')
        @case('download')
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M7.5 11.25 12 15.75m0 0 4.5-4.5M12 15.75V3" />
            @break
        @case('filter')
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 5.25h16.5M6.75 12h10.5m-7.5 6.75h4.5" />
            @break
    @endswitch
</svg>
