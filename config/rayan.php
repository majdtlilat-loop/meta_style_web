<?php

/*
|--------------------------------------------------------------------------
| RAYAN — the assistant, its provider, and its hard limits
|--------------------------------------------------------------------------
|
| docs/27-RAYAN.md §§5, 15.
|
| PLATFORM configuration, not tenant data. The API key is Meta Style's, the
| approved model list is Meta Style's, and the safety ceilings are Meta Style's.
| A center chooses whether the assistant is on, what tone it takes and which of
| the APPROVED models it uses — never a key, never a URL, never an arbitrary
| model id (§5).
|
| That division is what makes the commercial model work: Meta Style pays the
| provider and sells centers an allowance, so a center that could supply its own
| key or name its own model would be choosing how much Meta Style spends.
|
*/

return [

    'http' => [
        /*
         * Seconds. A provider that does not answer must not hold a worker —
         * and a timeout becomes a hand-off to a human, never a retry, because
         * a retried run costs the center a second run for the same message.
         */
        'timeout' => (int) env('OPENAI_TIMEOUT', 30),
        'connect_timeout' => (int) env('OPENAI_CONNECT_TIMEOUT', 5),
    ],

    'providers' => [

        'openai' => [
            /*
             * The key is PLATFORM-side and secret. It is never stored in a
             * tenant database, never shown to a center, and never audited.
             *
             * Absent means the adapter reports itself UNAVAILABLE: conversations
             * hand off to staff instead of failing at a customer. That is the
             * correct behaviour for a deployment that has not been given a key
             * yet, and it is what the test suite runs with.
             */
            'api_key' => env('OPENAI_API_KEY'),

            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),

            /*
             * The model a center gets unless it selects another APPROVED one.
             *
             * A booking assistant is a high-volume, narrow task: read a short
             * message, pick a tool, write two sentences. The balanced model is
             * the right default for that, and the flagship would be paying
             * reasoning budget for "what time do you close".
             *
             * Model ids move. This one was verified against OpenAI's published
             * model list on 2026-09-20; it is configuration precisely so that
             * changing it is not a deploy (§5).
             */
            'model' => env('OPENAI_MODEL', 'gpt-5.6-terra'),

            /*
             * What a center may choose between. An allow-list, because a model
             * id is a cost decision: an arbitrary id would let a center spend
             * Meta Style's budget, or name one that does not exist and break
             * every conversation for their customers.
             */
            'approved_models' => [
                'gpt-5.6-terra',
                'gpt-5.6-luna',
                'gpt-5.6-sol',
            ],

            /*
             * PROVIDER-SIDE RETENTION IS OFF.
             *
             * OpenAI's Responses API stores responses for 30 days by DEFAULT
             * (verified 2026-09-20) and makes them readable from the dashboard.
             * Meta Style sends customer messages, so that default would place a
             * center's customers' conversations in a third-party account under
             * a retention policy nobody here controls and no center agreed to.
             *
             * `store: false` on every request. Multi-turn works because the
             * transcript is sent in full each time — the adapter is stateless
             * by construction, so there is nothing lost by not retaining it
             * (§10).
             */
            'store' => (bool) env('OPENAI_STORE', false),
        ],
    ],

    /*
     * Hard per-run ceilings. SAFETY, not commerce.
     *
     * Separate from the allowance a center buys, and for a different job: these
     * stop one run from looping, and the allowance stops a month from costing
     * too much. A center with a huge allowance is still bounded here, because
     * "50,000 runs a month" has never meant "one run may call forty tools"
     * (§15, docs/26-USAGE-QUOTAS.md §3).
     *
     * Every one of them is a TERMINATION guarantee. There is no configuration
     * of these values that produces an unbounded agent loop.
     */
    'limits' => [
        /*
         * How many times the model may be asked within one run.
         *
         * A turn is: the model speaks or requests tools, tools run, repeat.
         * Four is enough for "which branch → what's available → book it →
         * confirm" and short enough that a model talking to itself is stopped
         * long before it costs anything notable.
         */
        'max_turns' => (int) env('RAYAN_MAX_TURNS', 4),

        /*
         * Total tool calls across the whole run, not per turn. A model asking
         * for the same availability six times is a loop, and per-turn counting
         * would not catch it.
         */
        'max_tool_calls' => (int) env('RAYAN_MAX_TOOL_CALLS', 8),

        /*
         * Per provider request. A WhatsApp reply is two or three sentences; a
         * ceiling here bounds cost and stops a runaway generation.
         */
        'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS', 800),

        /*
         * Wall clock for the WHOLE run, across every turn, in seconds.
         *
         * The backstop the per-request timeout cannot provide: four turns that
         * each take 29 seconds are four successful requests and one customer
         * who waited two minutes. Checked between turns.
         */
        'max_seconds' => (int) env('RAYAN_MAX_SECONDS', 45),
    ],

];
