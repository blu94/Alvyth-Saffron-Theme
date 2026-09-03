# Dishes — the tabs this theme adds

The Saffron theme adds two tabs to a product's own edit screen. Everything else on that screen
is the shop's ordinary product form; these two are about running a kitchen.

Open a dish from **Products**, and you will find **Availability** and **Modifiers** beside the
usual tabs.

---

## Availability — which branches make this dish

Use this when a dish belongs to some branches and not others: a Penang-only laksa, a brunch
menu one branch runs and the others do not.

| Field | What it does |
|---|---|
| **Dishes Only These Branches Make** | Leave it **empty** and every branch serves the dish. That is the normal case and what almost every dish wants. Name one or more branches and the dish becomes **exclusive to them** — it disappears from every branch you did not name |

Two things worth knowing before you use it.

**Empty means everywhere, not nowhere.** An empty list is not a restriction waiting to be
filled in; it is the absence of one. This is the opposite of how a permissions screen usually
reads, and it is deliberate: a dish nobody has made exclusive is on the whole menu.

**It hides, it does not grey.** A customer browsing a branch that does not make this dish will
not see it at all — not greyed, not with a note, simply absent. If what you mean is *"we have
run out of it tonight"*, this is the wrong screen: use **Sold Out Here Today** on the branch
instead, which keeps the dish on the menu wearing its badge. Hiding a dish a regular came for
reads as a broken website; greying it reads as a busy kitchen.

The rule is enforced when the order is placed, not only when the menu is drawn. A customer who
had the dish in their basket before you made it exclusive is refused at checkout, with a
sentence naming the dish and the branch.

---

## Modifiers — the questions this dish asks

This is where a dish gets its *"Choose your protein"*, its *"How spicy?"*, its *"Add extras"*.
The questions themselves are built once under **Modifier Groups**; this tab decides which of
them this dish asks, in what order.

| Field | What it does |
|---|---|
| **Questions This Dish Asks** | The groups attached to this dish. Drag to reorder — the customer is asked in this order |
| **Required** | Per dish, overriding the group's own setting. A side salad may be optional on one dish and compulsory on another, and this is where you say so |

**A compulsory question is genuinely compulsory.** It is enforced on the server when the cart
is priced, not merely in the browser, so an order cannot reach the kitchen with the question
unanswered — including an order placed on a dish with sizes, which used to be a way around it.

**Paid answers are charged.** If an answer carries a price change, that figure is added to the
line and the customer is billed exactly what they were quoted. There is no separate step to
switch that on.

**Attaching one question to many dishes** is quicker from the other end: open the question
under **Modifier Groups** and use **Attach To Dishes**. It leaves a dish that already asks the
question completely alone, so a per-dish *Required* setting you have tuned is never overwritten
by a bulk apply aimed at other dishes.
