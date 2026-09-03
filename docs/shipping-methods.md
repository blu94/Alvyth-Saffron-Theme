# Delivery & pickup methods — the Branch section

The Saffron theme adds one section to a shipping method's edit screen. It appears only on a
method whose type is **Pickup**, because it only means anything there.

---

## Branch (Pickup methods)

| Field | What it does |
|---|---|
| **Outlet** | The branch this collection method represents. Leave it as *No linked branch* for a single-outlet shop, or for a method that is not about a particular branch |

**Why the link exists.** A customer collecting an order picks a *method*, not a branch — the
method's own name is what they read at checkout, so name it the way a customer would say it
("Collect from Bangsar"). The link here is how the shop knows which kitchen that means. It puts
the branch on the kitchen ticket, files the order under the right branch on the Kitchen Queue,
and lets a counter filter the board down to their own branch.

**One method per collecting branch** is the shape this expects. If two methods point at the same
branch, both work, but the customer is being asked a question with two identical answers.

**A delivery method needs no link.** Deliveries are routed to a branch at checkout — by the only
branch that delivers, or by the nearest one that covers the address — and the order records why
it chose, so an unrouted delivery says so rather than silently belonging to nobody.

**If you change the link**, orders already placed keep the branch they were placed with. The
link is read when the order is created, not looked up afterwards, so tonight's tickets do not
move branch under the counter's hands.
