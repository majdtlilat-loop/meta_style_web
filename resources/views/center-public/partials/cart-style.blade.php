{{--
    Styles for the cart and checkout pages. Static CSS: the only center values
    are the custom properties set on .cart-page from PublicPageAppearance
    (validated `#rrggbb` colours). Logical properties throughout, so Arabic and
    Kurdish mirror the layout.
--}}
<style>
    .cart-page { --cart-fg: #1c1f26; --cart-muted: #5b6472; --cart-line: #e6e8ee; --cart-surface: #ffffff; --cart-bg: transparent;
        color: var(--cart-fg); background: var(--page-bg, var(--cart-bg)); border-radius: 1rem; padding: clamp(1rem, 4vw, 2.5rem) clamp(.75rem, 3vw, 2rem); margin-block: 1rem 3rem; }
    .cart-page.cart-bg-dark { --cart-fg: #e8eaf0; --cart-muted: #98a1b0; --cart-line: #2a303c; --cart-surface: #151922; --cart-bg: #0d0f14; }
    .cart-preview-banner { margin: 0 0 1rem; padding: .45rem .8rem; border-radius: .5rem; background: #10131a; color: #fff; font: 600 .8rem/1.4 system-ui, sans-serif; text-align: center; }
    .cart-page__grid { display: grid; gap: 1.5rem; align-items: start; }
    .cart-layout-split .cart-page__grid { grid-template-columns: minmax(0, 1fr); }
    @media (min-width: 52rem) {
        .cart-layout-split.cart-summary-panel .cart-page__grid { grid-template-columns: minmax(0, 1fr) 18rem; }
    }
    .cart-layout-centered .cart-page__main { text-align: center; max-width: 34rem; margin-inline: auto; }
    .cart-layout-centered.cart-summary-panel .cart-page__grid { max-width: 34rem; margin-inline: auto; }
    .cart-page__logo { display: block; max-height: 2.75rem; width: auto; max-width: 10rem; object-fit: contain; margin-block-end: 1rem; }
    .cart-layout-centered .cart-page__logo { margin-inline: auto; }
    .cart-page h1 { margin: 0 0 .35rem; font-size: clamp(1.5rem, 4vw, 2.1rem); line-height: 1.15; }
    .cart-page__intro { margin: 0; color: var(--cart-muted); white-space: pre-line; }
    .cart-empty { margin-block-start: 1.5rem; padding: 2rem 1.25rem; border: 1px dashed var(--cart-line); border-radius: .9rem; background: var(--cart-surface); display: grid; gap: .5rem; justify-items: center; text-align: center; }
    .cart-empty__icon { display: grid; place-items: center; width: 3.25rem; height: 3.25rem; border-radius: 50%; color: var(--accent); background: color-mix(in srgb, var(--accent) 12%, transparent); }
    .cart-empty h2 { margin: .25rem 0 0; font-size: 1.15rem; }
    .cart-empty p { margin: 0; color: var(--cart-muted); max-width: 28rem; white-space: pre-line; }
    .cart-cta { display: inline-flex; align-items: center; min-height: 2.75rem; margin-block-start: .75rem; padding: .6rem 1.2rem; border: 2px solid var(--accent); border-radius: .6rem; background: var(--accent); color: var(--on-accent); font-weight: 600; text-decoration: none; }
    .cta-outline .cart-cta { background: transparent; color: var(--accent); }
    .cta-pill .cart-cta { border-radius: 999px; }
    .cart-summary { padding: 1.1rem 1.25rem; border: 1px solid var(--cart-line); border-radius: .9rem; background: var(--cart-surface); text-align: start; }
    .cart-summary h2 { margin: 0 0 .35rem; font-size: 1rem; }
    .cart-summary p { margin: 0; color: var(--cart-muted); font-size: .92rem; }
    .cart-summary--inline { margin-block-start: 1rem; }
</style>
