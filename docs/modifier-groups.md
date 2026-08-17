# Modifier Groups

A modifier group is **a question your menu asks about a dish**, together with the options a customer can pick from. "Choose your side" with *fries / salad / rice*. "Sugar level" with *normal / less / none*. "Add extras" with *extra cheese / fried egg / no onions*.

You write the question once here, then attach it to as many dishes as you like. Change the wording later and every dish that asks it updates at the same time — you do not go around editing each dish.

## Modifier groups are not variants

These two look similar and do very different jobs. Getting them the wrong way round is the most common mistake, so it is worth thirty seconds:

- A **variant** is a different version of the dish that you stock and sell separately — Regular vs Large. It has its own SKU, its own price and its own stock count. Variants live on the dish itself, under the **Variants** tab.
- A **modifier** is a *choice the customer makes about the dish they already picked* — no onions, extra cheese, less sugar. It does not have its own SKU or stock. It is printed on the kitchen ticket so the cook knows what to make.

Rule of thumb: if the kitchen needs to *know* it, it is a modifier. If your accountant needs to *count* it, it is a variant.

## Where to find it

Open **Modifier Groups** in the sidebar. It has three links:

- **List** — everything you have written.
- **Attach To Dishes** — put one question on many dishes in a single action.
- **Add Group** — write a new question. The **New Group** button at the top-right of the list does the same.

## The list screen

Each row is one question, with these columns:

- **ID** — the automatic reference number.
- **Question** — the group title, as the customer sees it.
- **Slug** — the fixed internal name, generated from the question when you first save it. It does not change when you rename the question, because the kitchen ticket and the cart are keyed on it.
- **Selection** — whether the customer picks **one** option or **several**.
- **Options** — how many choices this question offers.
- **Dishes** — how many dishes currently ask this question. A `0` here means you have written the question but not attached it to anything yet, so no customer will ever see it.
- **Status** — **Active** or **Inactive**.
- **Created At** — when the group was written.

Two filters sit above the list: **Status**, and **Selection** (single or multiple).

Each row has three actions: the **eye** opens a read-only view of the group — handy for
checking a question without any risk of changing it — the **pencil** edits it, and the
**bin** deletes it after asking.

Opening a group — to view or to edit — also shows **Dishes That Ask This Question** at the
bottom of the form: every dish this question currently reaches, by name. It is read-only. A
dish decides which questions it asks, and this is only the mirror of those decisions, so if the
list is not what you expect the place to fix it is the dish, or the Attach To Dishes screen
below.

## Writing a group

### The Question

- **Question** — what the customer is asked, e.g. *Choose your side*. Shown as the heading on the dish sheet and printed on the kitchen ticket as the option label. Required, and translatable.
- **Helper Text** — an optional line under the heading, e.g. *Swap for onion rings at no charge*. Use it for the small print you would otherwise be asked about at the counter.
- **Selection** — **Single** shows radio buttons, so exactly one option can be chosen. **Multiple** shows checkboxes.
- **Minimum Selections** — set **1** to make the question compulsory: the customer cannot add the dish to the cart without answering. Set **0** to let them skip it.
- **Maximum Selections** — the cap on a multiple-choice question, e.g. *pick any 3 toppings*. Leave it empty for no limit. It is ignored when the selection is Single, which always allows exactly one.
- **Status** — set a group **Inactive** to pull it off the storefront without deleting it or detaching it from anything. Useful when an ingredient is off for the season.

### The Options

One row per choice. Add them with **Add Option**.

- **Option** — what the customer picks, e.g. *Extra cheese*. Required, and translatable.
- **Price Change** — what this option adds to the dish. `2.50` charges RM 2.50 more; leave it at `0.00` for a free choice like "no onions". It may be negative, e.g. `-1.00` for leaving something out.
- **Pre-selected** — this option is ticked by default. On a single-selection group only the first pre-selected option is used.
- **Status** — hide one option without deleting it or touching the rest of the group.

### How Price Change is charged

The amount is added to the **dish's own price**, per portion. Order two burgers with extra cheese at 2.50 and you are charging for two cheeses, which is what the customer expects.

It appears as one line on the cart, the order and the receipt — *Charcoal Chicken · 17.00*, with the chosen options listed underneath it — the same way every food-delivery app shows it. There is no separate "Extra cheese" line item, and you do not create a product for it.

**The price is worked out on the server, from these rows.** Nothing the customer's browser sends is trusted, so the total on the dish page and the total charged cannot drift apart, and a changed price applies the moment you save it.

> **Earlier versions of this guide told you to keep every Price Change at 0.00.** That was true: the figure was shown to the customer and then not collected. It is now charged properly, so you can price add-ons normally. If you set prices during that period, check them — they were being given away.

## Attaching a group to a dish

Attaching happens **on the dish**, not here.

Open the dish under **Store → Products**, go to its **Modifiers** tab, and click **Add Group**. For each one you attach:

- **Group** — search and pick the question.
- **Required** — leave on **Inherit from group** to use the group's own Minimum Selections. Choose **Required** or **Optional** only where this one dish should differ, e.g. a side is compulsory with the set meal but optional à la carte.

The order of the rows is the order the questions are asked on the dish sheet, so put the decision that matters most at the top.

Attach the **parent dish** and every variant of it inherits the question — you do not attach a group to Regular and Large separately.

## Attaching one question to many dishes at once

Every burger asking "Choose your side" is thirty dishes, and thirty product forms is not a job
anybody should do by hand. **Modifier Groups → Attach To Dishes** does it in one action.

1. Pick the **Question**.
2. Pick every **Dish** it applies to. Type to search; each one you pick becomes a chip you can
   remove with its ×. Only parent dishes are listed — attach the parent and the variants
   inherit it.
3. Set **Required** if these dishes should differ from the question's own Minimum Selections.
4. Leave **Action** on *Attach to these dishes* and press **Apply**.

**Last Action** then tells you exactly what happened — how many dishes it was added to, and how
many already had it.

Three things are worth knowing before you use it in anger:

- **A dish that already asks the question is not touched at all.** Not its position, not its
  Required setting. If somebody set "Optional" on one dish from that dish's own form, a bulk
  Apply carrying "Required" leaves it alone — the Required box here only applies to the dishes
  that are getting the question for the first time. Change the odd one out on the dish itself.
- **The question is added at the bottom** of each dish's list, exactly where the dish's own
  Modifiers tab would have put a new row. Nothing is reordered. If a particular dish should ask
  it first, drag it up on that dish afterwards.
- **Detaching is the same screen.** Switch **Action** to *Detach from these dishes* and Apply.
  It removes the question from the dishes you named and leaves every other question where it
  was; a dish that never asked it is reported and otherwise ignored.

Below the form, **Where Each Question Is Asked** is the picture of your whole menu: how many
dishes ask each question, and — the useful one — **Dishes That Ask Nothing**. A dish sitting in
that list takes no choices at all at the counter, which is usually a question somebody forgot to
attach. Both refresh the moment you Apply, so you can work through the list without reloading.

## A worked example

You sell Nasi Lemak in Regular and Large, and every order needs a protein choice and can take extras.

1. **Variants** on the dish: Regular and Large — different prices, different stock. Not modifiers.
2. **Modifier group "Choose your protein"** — Selection: Single, Minimum Selections: **1** (compulsory). Options: *Ayam Goreng*, *Rendang*, *Sambal Sotong*.
3. **Modifier group "Add extras"** — Selection: Multiple, Minimum: 0, Maximum: 3. Options: *Extra sambal*, *Fried egg*, *Extra peanuts*.
4. On the Nasi Lemak product, open **Modifiers** and attach both — protein first, extras second.

A customer now picks a size, must answer the protein question, and may add up to three extras. The kitchen ticket carries all of it.

## Things worth knowing

- **A group with no dishes attached does nothing.** Writing the question is only half the job; the **Dishes** column on the list tells you which half you are on, and Attach To Dishes is the fastest way to finish it.
- **Deleting a group** removes it from every dish that asked it. Its options go with it, and come back with it if it is restored. If you only want it off the menu for now, set it **Inactive** instead.
- **Renaming a question** changes it everywhere at once. That is the point of writing it once — but it does mean a rename is never local to one dish.
- **Past orders are unaffected by later edits.** An order records the wording that was on the menu at the time, so renaming or deleting a group never rewrites history.
