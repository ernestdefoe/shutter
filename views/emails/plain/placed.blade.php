<x-mail::plain.notification>
<x-slot:body>
{!! $translator->trans('ernestdefoe-shutter.email.placed.plain', [
'{place}' => $translator->trans('ernestdefoe-shutter.lib.place.'.min($blueprint->placement, 4), ['{n}' => $blueprint->placement]),
'{title}' => $blueprint->discussion->title,
'{url}' => $url->to('forum')->route('discussion', ['id' => $blueprint->discussion->id]).'?photo='.$blueprint->entryId,
]) !!}
</x-slot:body>
</x-mail::plain.notification>
