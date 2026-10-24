<x-mail::plain.notification :title="$translator->trans('ernestdefoe-gauntlet.email.checkin.title', ['title' => $blueprint->tournament->title])">
<x-slot:body>
{!! $translator->trans('ernestdefoe-gauntlet.email.checkin.body', ['title' => $blueprint->tournament->title]) !!}

{!! $url->to('forum')->route('gauntlet.tournament', ['id' => $blueprint->tournament->id.($blueprint->tournament->slug !== '' ? '-'.$blueprint->tournament->slug : '')]) !!}
</x-slot:body>
</x-mail::plain.notification>
