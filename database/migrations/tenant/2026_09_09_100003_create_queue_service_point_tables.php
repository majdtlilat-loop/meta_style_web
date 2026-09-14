<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a called customer is told to go, and the screens that tell them.
 *
 * ## A service point is not a resource
 *
 * Sometimes it is one — "Laser Room 2" is a room the center also books. Often
 * it is not — "Reception Desk 1" and "Gate A" are stations nothing reserves.
 * So the link is OPTIONAL and one-directional, and calling a ticket to a
 * resource-linked point assigns NOTHING: actual resource capacity is only ever
 * taken by the Phase 7 Journey Actions, under the branch lock, against the
 * combined occupancy check (docs/17-QUEUE.md §8, ADR-050).
 *
 * The alternative — a polymorphic "anything can be a destination" — was
 * rejected. Every consumer would need to know which kind it had, and the TV
 * needs one thing: a short code and a name.
 *
 * ## A display is a first-class record
 *
 * It has to be. The public URL must name something real, so that a display
 * switched off stops answering, and so that its scope, language and voice
 * settings are not configuration smuggled into an unrelated table
 * (docs/17-QUEUE.md §9).
 *
 * Three scopes, and only three: branch-wide, one department, or one service
 * point. That covers a center with one screen at the door, a laser floor with
 * its own screen, and a counter with a screen above it. It is not a signage
 * CMS, and it never renders center-authored HTML (ADR-038).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_service_points', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // A service point stands somewhere. Branch scope is what keeps one
            // branch's tickets off another's screens and out of its sequences
            // (docs/17-QUEUE.md §20).
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();

            // Optional: "Reception Desk 1" serves everybody; "Laser Room 2"
            // belongs to a department.
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();

            $table->json('name');

            /*
             * What the television shows next to the number: "L2", "R1".
             *
             * Separate from the name on purpose — a name is translated and can
             * be long, and neither property is wanted on a screen read from
             * across a room.
             */
            $table->string('display_code', 8);

            // Tickets issued to this point carry this letter. Null falls back
            // to the department's, then to 'A'.
            $table->string('ticket_prefix', 4)->nullable();

            // The optional link, never an equivalence.
            $table->foreignId('resource_id')->nullable()->constrained('resources')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            /*
             * Two service points called "R1" in one branch would make a call
             * ambiguous at exactly the moment it has to be obvious — a customer
             * looking up at a screen. Unique per branch, not per center: two
             * branches may each have a Reception Desk 1.
             */
            $table->unique(['branch_id', 'display_code']);

            /*
             * The destination picker and the display configuration list:
             *
             * SELECT ... FROM queue_service_points
             * WHERE branch_id = ? AND is_active = 1 ORDER BY sort_order, id
             */
            $table->index(['branch_id', 'is_active', 'sort_order']);
        });

        Schema::create('queue_displays', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();

            // Staff-facing: "Entrance TV", "Laser floor screen".
            $table->string('name', 190);

            /*
             * The opaque identifier in the public URL, beside the center's own
             * public key: /q/{center}/{display}.
             *
             * Not the uuid and not the id. A separate value can be rotated if a
             * screen is stolen or a link leaks, without disturbing anything else
             * that references this row — the same reasoning as the tenant public
             * key (ADR-027, ADR-036).
             */
            $table->string('public_key', 32)->unique();

            /*
             * Scope, and exactly one of three shapes:
             *
             *   both null            the whole branch
             *   department_id set    one department's calls
             *   service_point_id set one counter's calls
             *
             * Validated in the Action, which also checks the department or
             * service point belongs to THIS branch — a screen showing another
             * branch's calls is the one thing §20 forbids outright.
             */
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('service_point_id')->nullable()
                ->constrained('queue_service_points')->nullOnDelete();

            // The language the screen renders and announces in. Null follows
            // the center's default locale.
            $table->string('locale', 12)->nullable();

            // How many past calls the screen lists under "now calling". Bounded
            // here so a display can never ask for the whole day (§19).
            $table->unsignedTinyInteger('recent_calls_limit')->default(5);

            $table->boolean('sound_enabled')->default(true);
            $table->boolean('voice_enabled')->default(true);

            /*
             * Ordered list of locales to speak, e.g. ["ar","en"]. JSON because
             * it is an ordered list read as a whole and never queried — no
             * functional index, which would be MySQL-8-only (ADR-033).
             */
            $table->json('voice_locales')->nullable();

            // A screen switched off stops answering. The public route fails
            // closed on this (§9).
            $table->boolean('is_active')->default(true);

            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            /*
             * The public display route, and its only lookup:
             *
             * SELECT ... FROM queue_displays WHERE public_key = ? AND is_active = 1
             *
             * The unique index above serves it; nothing else reads this table by
             * anything but the branch list below.
             */
            $table->index(['branch_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_displays');
        Schema::dropIfExists('queue_service_points');
    }
};
