# Reconstruction of the original PHP backend

The PHP sources of https://ples.junak-sumperk.cz/ were not available. This is an
educated guess of what `index.php` and `ajax.php` did, derived from the recovered
`original/script.js`, `original/style.css` and the rendered HTML. Statements are
marked as **certain** (directly visible in client code) or **guess**.

## index.php

- **Certain:** printed `var maxTicket = <n>;` into the page (a per-reservation
  ticket limit, read from config or DB). In the post-ball state the value was
  empty, producing a JS syntax error.
- **Certain:** had at least two modes - "sale open" (reservation form + hall map)
  and "after the ball" (thank-you text with a photo link). The previous year's
  landing page was left in an HTML comment.
- **Certain:** the sale page contained:
  - `#emailInput` (e-mail), `#overeni` (verification message), `#odpocet` (countdown);
  - `.map-viewport > #checkbox-container` with a hall plan `<img>` and one
    `<input type="checkbox" name="places[]" id="<seatId>" data-id="<seatId>">`
    per seat, wrapped in `<span id="span<seatId>" class="chair">` that was
    absolutely positioned over the image (positions probably stored in the DB
    or hard-coded in PHP);
  - `.quantity > input#numeric[type=number][min][max]` for tickets without a seat;
  - a submit button `#odeslat` - most likely a regular `<form method="post">`
    that finalized the reservation (**guess**: then sent an e-mail with payment
    details - bank transfer with a variable symbol).
- **Guess:** `.adminInfo` (red banner) was shown to a logged-in organizer.
- **Guess:** `.infTable` / `.infTable2` held event info and the contact form
  (name, phone) with icons.

## ajax.php

A single endpoint dispatching on which POST field was present:

| POST fields | Behaviour | Response |
|---|---|---|
| `email` | Looks up the e-mail. New -> creates a reservation row, stores its id in the session. Existing unfinished -> resumes it. Already finalized -> refuses. | JSON `{"state":"new"|"exist"|"error","id":<ticket_id>}` |
| `idCh`, `emailR`, `act=true` | Holds seat `idCh` for the session's reservation: sets `state='book'`, `ticket_id`, `times=time()`. Fails if someone else holds or reserved it. | `save` / `error` |
| `idCh`, `emailR`, `act=false` | Releases the seat (back to `free`). | `save` / `error` |
| `ids[]` | Returns state of every seat for polling (every 2 s). | JSON `{ "<id>": {state, ticket_id, times, timesA} }` where `timesA` is the current server time |
| `numeri=true` / `numeric=false` | Increments / decrements the number of standing tickets in the session. | none |
| `sesTime=odstran` | Clears the hold timer in the session when nothing is selected. | none |

Data model (**guess**, from the JSON keys): a seats table with `id`, `state`
(`free` / `book` / `reserved`), `ticket_id` (reservation id) and `times`
(UNIX timestamp of the hold), plus a reservations table keyed by e-mail.

Hold expiry: **certain** that a hold was considered expired when
`timesA - times > 120`; the client also reloaded the page after 120 s from the
first selection. **Guess:** expired holds were never cleaned up in the DB,
only treated as free by the client.

## Weaknesses of the original (fixed in the new implementation)

- Hold expiry and the ticket limit were enforced only in the browser.
- The polling response leaked reservation ids of other people.
- No CSRF protection; the e-mail was trusted from every request.
- Race conditions: two people could likely hold the same seat at once.
- jQuery loaded twice; 10 MB background image.

## New implementation (see `src/ReservationService.php`)

Same user flow, but all rules live on the server:

1. Enter e-mail -> `start` creates or resumes a draft reservation bound to the session.
2. Click seats -> `hold` / `release`. Holds are atomic `UPDATE ... WHERE state = 'free'`,
   expire after `hold_seconds` since the last change, and are cleaned up on every request.
3. Choose standing tickets -> `standing`. Limit: `max_ticket` per reservation,
   `standing_capacity` in total (checked again on confirm).
4. Fill in name (+ phone) -> `confirm` turns held seats into `reserved` in one transaction.
5. Payment is not implemented yet; the reservation id is used as the variable symbol.
