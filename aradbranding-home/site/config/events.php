<?php

// event name => list of handler classes (handle(Container $c, array $events)). Handlers must tolerate re-delivery.
return [
    'page_viewed' => [App\Modules\Analytics\CountPageViews::class],
    'proposal_viewed' => [App\Modules\Analytics\CountProposalViews::class],
    'proposal_sent' => [App\Modules\Analytics\CountProposalSends::class, App\Modules\Notifications\NotifyHandler::class],
    'letter_sent' => [App\Modules\Notifications\NotifyHandler::class],
    'letter_replied' => [App\Modules\Notifications\NotifyHandler::class],
    'connection_created' => [App\Modules\Notifications\NotifyHandler::class],
];
