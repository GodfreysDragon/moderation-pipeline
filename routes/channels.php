<?php

use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// A user's own notifications and submissions list.
Broadcast::channel('App.Models.User.{id}', function (User $user, int $id) {
    return $user->id === $id;
});

// One submission's page: whoever may view it.
Broadcast::channel('submissions.{submission}', function (User $user, Submission $submission) {
    return $user->can('view', $submission);
});

// Queue and submission changes for staff screens.
Broadcast::channel('moderation', function (User $user) {
    return $user->isStaff();
});
