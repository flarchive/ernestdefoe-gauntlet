<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'gauntlet.view' => Group::GUEST_ID,
    'gauntlet.join' => Group::MEMBER_ID,
    'gauntlet.predict' => Group::MEMBER_ID,
    'gauntlet.create' => Group::MODERATOR_ID,
    'gauntlet.manage' => Group::MODERATOR_ID,
]);
