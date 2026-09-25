<?php

/*
|--------------------------------------------------------------------------
| Reviews
|--------------------------------------------------------------------------
|
| docs/22-REVIEWS.md §§6, 17.
|
| Two numbers the product has an opinion about, in one place rather than
| scattered through the code that happens to need them. Both are read at the
| moment they are used, so changing one does not rewrite history: an invitation
| already issued keeps the expiry it was minted with, and a review already
| submitted keeps the alert it did or did not raise.
|
*/

return [

    /*
     * How long a review link works.
     *
     * Long enough that a customer who meant to answer at the weekend still can,
     * short enough that a link photographed on a counter stops being a way into
     * a visit. Thirty days, and it is a capability rather than a login, so the
     * bound matters.
     */
    'invitation_valid_days' => (int) env('METASTYLE_REVIEW_VALID_DAYS', 30),

    /*
     * At or below this, the center hears about it.
     *
     * Two stars out of five: a rating a manager should look at the same day,
     * not a statistic to read next month. One place, so the alert, the staff
     * screen and the documentation cannot drift apart (§17).
     */
    'low_rating_threshold' => (int) env('METASTYLE_REVIEW_LOW_RATING', 2),

];
