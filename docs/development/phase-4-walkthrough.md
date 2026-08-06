# Phase 4 — Host walkthrough script

> The half of Q9 that needs a person. Everything below has an automated equivalent that passes;
> none of them is somebody using the product.
>
> The console and HTTP halves were done on 2026-08-06 against `laravel-test` — see Q9 in
> `open-questions.md`. What follows is the browser.

## Before you start

The demo automations are already seeded in `laravel-test` by `pandora-demo-seed.php` (delete that
file when you are finished with it). `demo-heartbeat` is deliberately **disabled** — it fires every
minute against a real paid model, and leaving it running unattended is not something to do by
accident.

```bash
cd /home/michal/development/laravel-test
./vendor/bin/sail up -d          # if the stack is not already running
```

Open <http://localhost:8080/pandora> signed in as any user.

## 1. The page tells you what is wrong before you ask

**Automations** in the sidebar.

- [ ] Both demo automations are listed.
- [ ] `demo-webhook` says **"Waits for its trigger"** under Next run rather than leaving a blank cell.
      A gap reads as missing data; this is the actual state of an externally-woken automation.
- [ ] Because nothing has fired on the cron yet, the page names **`schedule:run`** at the top. This
      is the single most common "automation doesn't work" cause and it is invisible from inside the
      application.

## 2. Enabling recomputes rather than trusting

- [ ] Press **Enable** on `demo-heartbeat`. The notice names the next run, in Europe/Copenhagen.
- [ ] The Next run column shows a time about a minute out, **in Copenhagen time** — two hours ahead
      of UTC in summer. The timezone is the one whoever configured the automation was thinking in.

## 3. Watch it fire on a real clock

In a second terminal:

```bash
./vendor/bin/sail artisan schedule:work     # the real scheduler, ticking every minute
./vendor/bin/sail artisan queue:work        # in a third, so runs actually execute
```

- [ ] Within two minutes, Last run changes from **Never**.
- [ ] Open the automation → **History**. There is an occurrence row, `dispatched`, linking to a run.
- [ ] Follow the run. Its trigger is `schedule` and it is stamped `observe_only`.

**Stop `schedule:work` when you have seen it.** The autonomy budget caps it at 10 runs an hour and
will disable the automation when it runs out — which is worth seeing once, deliberately, rather than
discovering on a bill.

## 4. The refusals

- [ ] **Schedule** tab → Edit → set the cron expression to `every tuesday-ish` → Save. It is
      **refused with the reason**, and the stored schedule is unchanged. An unparseable expression
      would otherwise store fine, produce a null next run, and present as an automation that simply
      never runs.
- [ ] **Behaviour** tab → Edit → open the Autonomy select. The `echo` agent is `suggest`, so
      **`Act within policy` is not offered**, and the help text says why. A level that would be
      silently clamped is not a choice.
- [ ] Change the autonomy budget to something small, save, and confirm the History tab records a
      `refused` occurrence with reason `autonomy_budget` once it is exhausted — and that the
      automation turns **itself** off.

## 5. The webhook panel

- [ ] `demo-webhook` → **Webhook** tab. The URL and a worked signature example are both shown.
- [ ] Press **Rotate secret**. The new secret appears **once**, with a warning.
- [ ] Reload the page. It is **gone** — there is no second chance to read it.
- [ ] Copy it before reloading and try it:

```bash
SECRET='<the secret you copied>'
BODY='{"order":"ORD-9"}'
TS=$(date +%s)
SIG=$(printf '%s.%s' "$TS" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" -hex | sed 's/^.*= //')

# 202 with a run id
curl -i -X POST http://localhost:8080/pandora/webhooks/demo-webhook \
  -H 'Content-Type: application/json' -H "X-Pandora-Signature: t=$TS,v1=$SIG" -d "$BODY"

# the same request again: 409
curl -i -X POST http://localhost:8080/pandora/webhooks/demo-webhook \
  -H 'Content-Type: application/json' -H "X-Pandora-Signature: t=$TS,v1=$SIG" -d "$BODY"
```

- [ ] The **Deliveries** table shows both — one accepted, one not — and the rejected one stores no
      payload, because it failed authentication and nothing in it is trustworthy.

## 6. The agent's side

- [ ] **Agents → Echo → Automations**. Both automations are listed.
- [ ] The autonomy shown is the **effective** level, not what each automation asked for.

## 7. Authorization

- [ ] In `tinker`, deny the ability for your user:
      `Gate::define('pandora.automations.manage', fn () => false);` — or remove whatever grants it.
- [ ] The **New automation** button, the Enable/Disable buttons, **Run now** and the proposal queue
      all disappear, and the page says the ability is what is missing.

## What this cannot cover

A live Reverb server (the host broadcasts to `log`, so the UI is on its polling fallback — which is
what acceptance criterion 22 requires to stay correct), and an automation left running unattended for
long enough to exercise the misfire policy against a genuine worker outage.
