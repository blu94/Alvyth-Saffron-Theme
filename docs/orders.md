# Orders — the Kitchen tab

The Saffron theme adds one tab to an order's own edit screen. Open any order and you will find
**Kitchen** beside the usual tabs.

Most of the time you will not use it. The **Kitchen Queue** — under *Service Hours* — is the
screen a counter watches all evening, and moving a ticket there is quicker than opening an
order. This tab is for the order you are already looking at: a customer is on the phone, you
have the order open, and you need to say where it has actually got to.

---

## Where This Order Is In The Kitchen

| State | What it means |
|---|---|
| **New** | Accepted, nobody has started it |
| **Preparing** | In the kitchen |
| **Ready** | Cooked. Waiting for the customer to collect, or for a rider |
| **Out for delivery** | With a rider, on its way |
| **Delivered** | Handed over. Done |

Leave it empty for an order that has not reached the kitchen at all.

**Changing it here does everything moving it on the queue does.** The order's own status and
its fulfillment status are brought into line, and the customer is told in the kitchen's words
rather than the shop's generic ones — one email and one bell entry per move, so *Ready* reads
as *ready to collect* and not as *partially fulfilled*.

**Ready and Out for delivery are the same to the rest of the shop.** They share an order status
and a fulfillment status; this tab is the only place the difference is recorded. That is why the
queue and this tab both exist in the same vocabulary — if they disagreed, a counter would have
to remember which screen was telling the truth.

**Moving it backwards is allowed** and sometimes right: a dish sent out and returned goes back
to *Preparing*. The customer is told about that move too, so use it deliberately rather than to
correct a mis-click — a mis-click is better fixed by setting the state you actually meant.
