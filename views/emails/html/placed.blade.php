<x-mail::html.notification>
    <x-slot:body>
        {!! $formatter->convert($translator->trans('ernestdefoe-shutter.email.placed.html', [
            '{place}' => $translator->trans('ernestdefoe-shutter.lib.place.'.min($blueprint->placement, 4), ['{n}' => $blueprint->placement]),
            '{title}' => $blueprint->discussion->title,
            '{url}' => $url->to('forum')->route('discussion', ['id' => $blueprint->discussion->id]).'?photo='.$blueprint->entryId,
        ])) !!}
    </x-slot:body>
</x-mail::html.notification>
