<?php

return [
    'max_group_participants'       => (int) env('SOCIAL_MAX_GROUP_PARTICIPANTS', 15),
    'typing_indicator_ttl_seconds' => 3,
    'typing_throttle_seconds'      => 1.5,
    'messages_per_page'            => 30,
];
