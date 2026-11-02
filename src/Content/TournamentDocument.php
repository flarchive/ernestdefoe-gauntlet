<?php

namespace ErnestDefoe\Gauntlet\Content;

use ErnestDefoe\Gauntlet\Tournament;
use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * A tournament page's title and description in the HTML itself, for link
 * previews and search engines. One query, on this page only, through the
 * same visibility as the API: a draft gives nothing away.
 */
class TournamentDocument
{
    public function __construct(private UrlGenerator $url)
    {
    }

    public function __invoke(Document $document, Request $request): Document
    {
        $id = (int) Arr::get($request->getQueryParams(), 'id');
        if ($id < 1 || $id > 2147483647) {
            return $document;
        }

        $tournament = Tournament::query()->whereVisibleTo(RequestUtil::getActor($request))->find($id);
        if (! $tournament) {
            return $document;
        }

        $description = Str::limit(trim((string) preg_replace('/\s+/u', ' ', (string) $tournament->description)), 200);
        $url = $this->url->to('forum')->route('gauntlet.tournament', ['id' => $tournament->id.($tournament->slug !== '' ? '-'.$tournament->slug : '')]);

        $document->title = $tournament->title;
        $document->canonicalUrl = $url;
        if ($description !== '') {
            $document->meta['description'] = $description;
            $document->head[] = '<meta property="og:description" content="'.e($description).'">';
        }
        $document->head[] = '<meta property="og:title" content="'.e($tournament->title).'">';
        $document->head[] = '<meta property="og:type" content="website">';
        $document->head[] = '<meta property="og:url" content="'.e($url).'">';
        $document->content = '<h1>'.e($tournament->title).'</h1>'.($description !== '' ? '<p>'.e($description).'</p>' : '');

        return $document;
    }
}
