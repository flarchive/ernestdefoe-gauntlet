<x-mail::html.notification :title="$translator->trans('ernestdefoe-gauntlet.email.match_ready.title', ['title' => $blueprint->tournament->title])">
    <x-slot:body>
        <p>{{ $translator->trans('ernestdefoe-gauntlet.email.match_ready.body', ['title' => $blueprint->tournament->title]) }}</p>
        <p><a href="{{ $url->to('forum')->route('gauntlet.tournament', ['id' => $blueprint->tournament->id.($blueprint->tournament->slug !== '' ? '-'.$blueprint->tournament->slug : '')]) }}">{{ $translator->trans('ernestdefoe-gauntlet.email.open') }}</a></p>
    </x-slot:body>
</x-mail::html.notification>
