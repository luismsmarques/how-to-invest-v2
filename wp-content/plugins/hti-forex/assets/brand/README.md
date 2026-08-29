# Telegram identity — the channel and the bot

Everything BotFather and the channel settings need, in one place, so nobody has
to reconstruct it from screenshots later. Regenerate the images with
`src/build.sh`.

## The images

`hti-forex-telegram-channel.png` · `hti-forex-telegram-bot.png` — 512×512, which
is the size Telegram recommends. It crops to a circle itself, so the art is
already circular and bleeds to the edge.

Both sit on the brand's navy disc (`#1E2147`) with the same hairline ring,
ported from `hti-social` `Brand::logo_svg()`. They differ where it counts — the
silhouette:

- **Channel:** the brand shield with a single rising line in coral `#FF6B5E`.
  One accent, no fine detail to lose. Reads as *the publication*.
- **Bot:** the rupee sign, no shield. Reads as *the tool*, and ₹ is the fastest
  signal there is for this audience.

That split is not decoration. In a chat list the avatar is **40 pixels wide**,
and these two will sit near each other; a person tells them apart by shape long
before reading a name. Earlier drafts that put candlesticks inside the shield
looked better at 512 and turned to mush at 40 — which is the only size that
decides whether someone opens the thing.

The ₹ is the site's own Poppins 800, which carries U+20B9. No hand-drawn
approximation, and no dependency on a font the render machine may not have —
`src/build.sh` loads the woff2 straight out of the theme.

## The bot's message images

`bot-start.png` · `bot-pip.png` · `bot-promo.png` — 2560×1440 (16:9), the
creatives from the designer.

| File | Where it appears |
|---|---|
| `bot-start.png` | the `/start` reply — shows the transaction, `5000` in, the table out |
| `bot-pip.png` | the "What's a pip?" button — 20 pips priced at ₹191 |
| `bot-promo.png` | offered in the broadcast composer |

They are sent as photos with the message as the caption, which caps at **1,024
characters** rather than a message's 4,096 — `test-bot-images.php` asserts both
captions fit, because going over does not truncate, it fails the send for
every recipient at once.

Telegram fetches each one from our URL the first time and returns a `file_id`;
everything after that sends the id, so a 250 KB PNG is never pulled off the
shared host again. The cache is fingerprinted by the file's mtime and size, so
replacing an image on the server invalidates its id without anyone having to
remember a cache exists.

## The channel

**Name** (25) — `HowToInvest · Forex India`

**Bio** (197 / 255):

```
Forex arithmetic for India, in plain English.

Pip values in ₹ · position sizing · market hours in IST.
Educational only — no signals, no trade calls, no tips.

Calculator bot: @HowToInvestForexBot
```

## The bot

**Name** (`/setname`, 24 / 64) — `HowToInvest ₹ Calculator`

The ₹ in the name earns its place: it is visible in the chat list and in search
results, and it says what the bot does before anyone reads a word. If you would
rather be found by people searching Telegram for the task instead of the brand,
`Forex Lot Size Calculator ₹` is the trade — more discoverable, less ours.

**Username** — `@HowToInvestForexBot` (19 / 32; must end in `bot`).
If it is taken: `@HowToInvestINRBot`, `@HTIForexINRBot`, `@LotSizeRupeeBot`.

**About** (`/setabouttext`, 107 / 120) — the profile page:

```
Send your account balance, get what a trade actually costs in ₹ — margin, pip value, stop cost. No signals.
```

**Description** (`/setdescription`, 479 / 512) — the empty-chat screen, which is
the last thing someone reads before deciding whether to press *Start*. It leads
with a worked example on purpose: the number does the persuading:

```
Send me your account balance and I'll price up the smallest trade you can place — in rupees.

₹5,000 → 0.01 lots · ₹223 margin locked · ₹9.55 a pip · a 20-pip stop costs ₹191, which is 3.8% of the account.

Type 5000, ₹1,00,000, 50k, or $100 if your account is in dollars. Buttons switch the pair and the leverage.

Educational only — no signals, no trade calls, no tips. Nothing about you is stored beyond this chat, and /stop erases it.

Free calculators: howtoinvest.pro/forex
```

**Commands** (`/setcommands`):

```
start - What this bot does
help - How to use it, and what it isn't
stop - Delete my data and stop messages
```

**Welcome message** — not set in BotFather. It is `Bot::start_text()` in
`includes/class-bot.php`, so it ships and is tested with the code rather than
living in a chat window with @BotFather where nobody can review it.
