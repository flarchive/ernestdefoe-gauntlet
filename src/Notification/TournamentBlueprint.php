<?php

namespace ErnestDefoe\Gauntlet\Notification;

use ErnestDefoe\Gauntlet\Tournament;
use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/**
 * Every Gauntlet notification is about a tournament; what happened, and in
 * which match, rides in the data. Subclasses name the type and, for the ones
 * worth an email, the views.
 */
abstract class TournamentBlueprint implements BlueprintInterface, AlertableInterface
{
    /** @param array<string, mixed> $data */
    public function __construct(public Tournament $tournament, public array $data = [], public ?User $from = null)
    {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->tournament;
    }

    public function getFromUser(): ?User
    {
        return $this->from;
    }

    public function getData(): mixed
    {
        return $this->data ?: null;
    }

    public static function getSubjectModel(): string
    {
        return Tournament::class;
    }

    /** For the mailable ones. @return array<string, string> */
    public function getEmailViews(): array
    {
        $type = static::emailView();

        return ['text' => "ernestdefoe-gauntlet::emails.plain.$type", 'html' => "ernestdefoe-gauntlet::emails.html.$type"];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-gauntlet.email.'.static::emailView().'.subject', ['title' => $this->tournament->title]);
    }

    protected static function emailView(): string
    {
        return '';
    }
}
