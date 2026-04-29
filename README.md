# Cookventory

Cookventory is a PHP/MySQL recipe app for browsing recipes, creating recipes, tracking pantry inventory, managing a shopping list, saving recipes, rating recipes, and moderating content through admin tools.

This README is written for a new developer joining the project. The goal is to help you understand how the app is structured, where important logic lives, and which files you should be careful with before making changes.

## Stack

- PHP
- MySQL with PDO
- Server-rendered HTML
- Vanilla JavaScript
- One shared stylesheet plus one standalone stylesheet for the usability form

There is no framework and no migration system. Most behavior is page-controller PHP plus helper files in `private/`.

## Project Layout

```text
Cookventory_Primary/
|- private/
|  |- app-helpers.php
|  |- auth.php
|  |- db-connect.php
|  |- DB-dump.sql
|  |- recipe-form-helpers.php
|  |- recipe-saves.php
|  |- role-helpers.php
|  `- unit-helpers.php
|- public/
|  |- assets/
|  |  |- CSS/
|  |  |  |- style.css
|  |  |  `- usability-test.css
|  |  `- JS/
|  |     `- script.js
|  |- includes/
|  |  |- admin-check.php
|  |  |- auth-check.php
|  |  `- navbar.php
|  |- uploads/
|  |- admin.php
|  |- create_recipe.php
|  |- edit_recipe.php
|  |- index.php
|  |- ingredient_search.php
|  |- login.php
|  |- logout.php
|  |- my_recipes.php
|  |- pantry.php
|  |- recipe.php
|  |- recipes.php
|  |- saved_recipes.php
|  |- shopping_list.php
|  |- temp_recipe_photos.php
|  |- usability_results.php
|  `- usability_test.php
|- CODE_STUDY_GUIDE.md
|- README.md
`- test_db.php
```

## How The App Is Structured

The app follows a simple pattern:

1. Start session or auth include
2. Load helpers from `private/`
3. Handle `POST` actions near the top of the file
4. Run queries
5. Render HTML in the same file

There is no controller/model/view separation beyond helper files. Most pages are “controller + view” in one PHP file.

That means the main architecture is:

- shared backend helpers in `private/`
- page-level request handling in `public/*.php`
- shared auth/navigation includes in `public/includes/`
- shared frontend behavior in `public/assets/`

## Important Shared Backend Files

### [private/db-connect.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/private/db-connect.php)

Creates the PDO connection.

Important note:
- this file currently contains hardcoded DB credentials
- if the project is shared outside a controlled class/dev environment, this should be moved to env-based config

### [private/app-helpers.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/private/app-helpers.php)

Small shared utility functions used throughout the app.

Main functions:
- `h()` for HTML escaping
- `formatQty()` for quantity formatting
- `ensureRecipeServingsColumn()` to self-heal the `servings_rcp` column
- `resolveServingCount()` for serving-size input

This is the lightest helper file, but it gets included almost everywhere.

### [private/auth.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/private/auth.php)

Handles signup and login.

What it does:
- starts the session
- validates signup input
- hashes passwords
- creates new users
- assigns new users the `user` role
- validates login credentials
- blocks inactive users
- stores `user_id`, `username`, and `role` in the session

### [private/role-helpers.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/private/role-helpers.php)

This file powers the role system and some runtime schema setup.

Main functions:
- `normalizeRoleName()`
- `ensureRoleLevels()`
- `getRoleIdByName()`
- `getUserPrimaryRole()`
- `setUserPrimaryRole()`
- `currentUserRole()`
- `isAdminRole()`
- `isAdminUser()`
- `isSuperAdminUser()`

Current roles:
- `user`
- `admin`
- `super_admin`

### [private/recipe-saves.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/private/recipe-saves.php)

Handles the saved recipe feature.

Main functions:
- `ensureRecipeSaveTable()`
- `saveRecipeForUser()`
- `unsaveRecipeForUser()`
- `getSavedRecipeIdsForUser()`

### [private/recipe-form-helpers.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/private/recipe-form-helpers.php)

Supports recipe creation and editing.

Main responsibilities:
- image upload and compression
- ingredient normalization
- ingredient auto-creation
- cuisine normalization
- duplicate-resistant cuisine creation

Main functions:
- `saveRecipePhotoUpload()`
- `normalizeIngredientName()`
- `resolveIngredientId()`
- `normalizeCategoryName()`
- `canonicalizeCategoryKey()`
- `resolveCuisineCategoryId()`

### [private/unit-helpers.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/private/unit-helpers.php)

This is one of the most important files in the app.

It handles the base-unit storage strategy used by pantry and shopping list features.

Main responsibilities:
- shopping list table setup
- unit lookups
- compatible unit matching
- stored quantity conversion
- merging duplicate/compatible shopping list rows
- merging duplicate/compatible pantry rows

Main functions:
- `ensureShoppingListTable()`
- `getUnitById()`
- `getBaseUnitByName()`
- `pantryDisplayKey()`
- `getCompatibleUnits()`
- `getCompatibleDisplayUnits()`
- `convertFromStoredBaseToDisplayQty()`
- `convertDisplayQtyToStoredBase()`
- `addOrMergeShoppingListItem()`
- `addOrMergePantryItem()`

If a quantity bug touches pantry or shopping list behavior, this file is usually involved.

## Shared Include Files

### [public/includes/auth-check.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/includes/auth-check.php)

Simple login gate for pages that require authentication.

### [public/includes/admin-check.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/includes/admin-check.php)

Wraps `auth-check.php` and blocks access unless the current user is admin or super admin.

### [public/includes/navbar.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/includes/navbar.php)

Shared navigation bar for the whole app.

Current navbar behavior:
- top row: logo and login/account
- bottom row: recipes, cuisine, popular, create recipe, search
- logged-in account dropdown includes pantry, shopping list, saved recipes, my recipes, and role-based admin links
- mobile search uses JS to open/close a hidden search form

## Main Pages

### [public/index.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/index.php)

Homepage.

Main responsibilities:
- check login state
- build pantry-based recommendations
- support partial-batch recommendation logic based on available pantry inventory
- show latest recipes
- show popular recipes based on ratings
- pick weekly rotating cuisine/protein sections
- render homepage carousels

This file contains a lot of homepage-specific business logic.

### [public/recipes.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/recipes.php)

Recipe browsing and filtering page.

Main responsibilities:
- text search
- multi-select filtering for cuisine, protein, diet, and course
- sorting by newest, cook time, or popularity
- save/unsave actions
- admin delete action from the list page
- first-image lookup for recipe cards

Important note:
- this page also deduplicates category names in the filter UI when duplicate rows exist in the DB

### [public/recipe.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/recipe.php)

Single recipe detail page.

This is one of the busiest files in the project.

Main responsibilities:
- load one recipe
- scale ingredient quantities by servings
- handle ratings
- handle save/unsave
- add missing ingredients to shopping list
- cook a recipe and subtract ingredients from pantry
- admin-only recipe removal
- render categories, image gallery, ingredients, steps, and optional YouTube embed

This file also manages flash messages for several different recipe actions.

### [public/create_recipe.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/create_recipe.php)

Recipe creation page.

Main responsibilities:
- require login
- collect recipe title, description, prep time, cook time, servings, YouTube link, photo
- collect ingredients and steps
- collect category tags
- support `Other` cuisine entry
- create missing ingredients/cuisine categories when allowed
- save recipe image after DB insert

Important note:
- the recipe is committed before optional image saving, so a photo failure does not block recipe creation

### [public/edit_recipe.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/edit_recipe.php)

Recipe editing page.

Main responsibilities:
- require login
- allow only the owner of the recipe
- load current recipe data
- update core recipe info
- update ingredients, steps, categories, and photo

### [public/pantry.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/pantry.php)

Pantry management page.

Main responsibilities:
- require login
- add pantry items
- auto-create ingredients when typed manually
- normalize compatible units behind the scenes
- update pantry quantities
- change display units across compatible units
- delete pantry items
- store display preferences in session

This page relies heavily on `unit-helpers.php`.

### [public/shopping_list.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/shopping_list.php)

Shopping list management page.

Main responsibilities:
- require login
- remember the last non-shopping page for the back link
- change display units
- single add to pantry
- bulk add selected items to pantry
- remove individual items
- remove selected items
- clear the list
- restore shortfall amounts back into the shopping list
- support print/PDF mode through `?print=1`

Important notes:
- this page uses redirect-after-POST throughout
- it relies heavily on session flash messages
- it stores quantities internally in normalized units but lets the user work in display units

### [public/saved_recipes.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/saved_recipes.php)

Shows recipes the current user has saved.

### [public/my_recipes.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/my_recipes.php)

Shows recipes created by the current user.

Main responsibilities:
- display owned recipes
- provide the edit shortcut
- provide owner-side soft delete

This is the only normal user-facing page where recipe editing is linked directly.

### [public/login.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/login.php)

Login/signup page. Uses `private/auth.php` for all real auth handling.

### [public/logout.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/logout.php)

Logs the user out and destroys the session.

### [public/admin.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/admin.php)

Admin/super-admin moderation page.

Main responsibilities:
- deactivate user accounts
- moderate recipes
- super-admin-only admin promotion/demotion

Rules currently enforced:
- admins can moderate general users
- super admins can also manage admin-owned content and assign admin status

### [public/usability_test.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/usability_test.php)

Public usability-testing form. No login required.

### [public/usability_results.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/usability_results.php)

Admin-only usability results page.

Main responsibilities:
- read saved submissions
- display them in browser
- provide full export and per-submission print/PDF actions

### [public/ingredient_search.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/ingredient_search.php)

Backend endpoint for ingredient autocomplete.

### [public/temp_recipe_photos.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/temp_recipe_photos.php)

Temporary utility page.

Main responsibilities:
- list the logged-in user’s recipes
- allow quick image upload/replacement

This is a maintenance shortcut, not a core product page.

### [test_db.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/test_db.php)

Admin-only database maintenance page.

Main responsibilities:
- inspect tables
- browse rows
- delete rows from tables with primary keys
- merge duplicate `category_cat` rows without losing linked recipe-category data

Important note:
- this page is not part of the normal app flow
- it is a maintenance tool and should be treated carefully

## Main Data Flows

### 1. Recipe -> Shopping List

Mostly handled in:
- [public/recipe.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/recipe.php)
- [private/unit-helpers.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/private/unit-helpers.php)

Flow:
1. User opens a recipe
2. Recipe ingredient needs are scaled by the active serving count
3. Pantry totals are compared against recipe totals using normalized base-unit math
4. Missing amounts are added to `shopping_list_item_shli`
5. Shopping list rows keep a display unit for the UI

### 2. Shopping List -> Pantry

Mostly handled in:
- [public/shopping_list.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/shopping_list.php)
- [private/unit-helpers.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/private/unit-helpers.php)

Flow:
1. User selects an item or several items
2. The selected display quantity is used for the pantry add
3. `addOrMergePantryItem()` merges compatible pantry rows
4. Shopping list item is removed
5. If the amount added was short, the user can restore the missing amount back into the shopping list

### 3. Pantry -> Homepage Recommendations

Mostly handled in:
- [public/index.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/index.php)

Flow:
1. Pantry quantities are normalized into comparable totals
2. Recipe ingredient requirements are normalized the same way
3. Homepage decides whether the user can make none, some, or all of a recipe
4. Recommended cards can open the recipe already scaled to the pantry-supported serving amount

### 4. Recipe Cooking -> Pantry Deduction

Mostly handled in:
- [public/recipe.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/recipe.php)

Flow:
1. User clicks `Cook This Recipe`
2. Ingredient amounts are scaled by servings
3. If `Modified recipe?` is enabled, custom quantities are used
4. Pantry rows are reduced or deleted
5. User gets a flash message if some ingredients were short

## Frontend Files

### [public/assets/CSS/style.css](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/assets/CSS/style.css)

Main site stylesheet.

What it covers:
- navbar
- shared layout
- page-specific sections
- mobile overrides
- current cozy palette/theme

Important note:
- this file is still large even after cleanup
- most visual regressions will come from here

### [public/assets/CSS/usability-test.css](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/assets/CSS/usability-test.css)

Standalone stylesheet for the usability form.

### [public/assets/JS/script.js](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/assets/JS/script.js)

Shared JavaScript for the app.

What it currently handles:
- navbar dropdowns
- mobile navbar search
- homepage carousel buttons
- recipe filter dropdowns
- ingredient autocomplete
- create-recipe custom cuisine toggle
- modified recipe toggle on recipe page
- shopping list select-all behavior
- auto-print behavior for print pages

## Database Notes

The app expects MySQL tables for:

- users
- roles
- user-role mapping
- recipes
- recipe images
- recipe steps
- ingredients
- units
- recipe ingredients
- category types
- categories
- recipe-category mapping
- pantry items
- shopping list items
- ratings
- saved recipes

Important note:
- some schema setup happens at runtime in PHP instead of through migrations

Examples:
- `ensureRoleLevels()`
- `ensureShoppingListTable()`
- `ensureRecipeSaveTable()`
- `ensureRecipeServingsColumn()`

That makes setup more forgiving, but it also means schema behavior is spread across code.

The current SQL reference file is:
- [private/DB-dump.sql](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/private/DB-dump.sql)

## How To Read This Codebase

If you are new to the project, this is a good reading order:

1. [README.md](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/README.md)
2. [private/app-helpers.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/private/app-helpers.php)
3. [private/role-helpers.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/private/role-helpers.php)
4. [private/unit-helpers.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/private/unit-helpers.php)
5. [private/recipe-form-helpers.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/private/recipe-form-helpers.php)
6. [public/includes/navbar.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/includes/navbar.php)
7. [public/index.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/index.php)
8. [public/recipes.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/recipes.php)
9. [public/recipe.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/recipe.php)
10. [public/pantry.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/pantry.php)
11. [public/shopping_list.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/shopping_list.php)
12. [public/create_recipe.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/create_recipe.php)
13. [public/edit_recipe.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/edit_recipe.php)

## Common Patterns In This Project

- Most files process `POST` actions first, then render.
- Complex pages usually redirect after `POST`.
- Sessions are used for auth, role state, flash messages, and some UI preferences.
- Pantry/shopping quantities are often stored in normalized base units, then converted back to display units for the user.
- Helper files reduce duplication, but most SQL still lives directly in page files.

## Hotspots And Risk Areas

These are the files most likely to cause cascading issues if changed carelessly:

- [public/recipe.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/recipe.php)
- [public/shopping_list.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/shopping_list.php)
- [public/pantry.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/pantry.php)
- [private/unit-helpers.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/private/unit-helpers.php)
- [public/assets/CSS/style.css](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/assets/CSS/style.css)
- [public/assets/JS/script.js](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/assets/JS/script.js)

If you change pantry, shopping list, unit conversion, or recipe actions, test the whole flow end to end afterward.

## Local Setup

To run locally:

1. Put the project where your PHP server can serve it
2. Set up the MySQL database
3. Update [private/db-connect.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/private/db-connect.php) for your local DB if needed
4. Serve the `public/` pages through your local web server

Useful helpers:
- [private/DB-dump.sql](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/private/DB-dump.sql) for schema/reference setup
- [test_db.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/test_db.php) for admin-only DB inspection/cleanup
- [CODE_STUDY_GUIDE.md](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/CODE_STUDY_GUIDE.md) for feature-oriented Q&A

## Short Summary

Cookventory is a server-rendered PHP recipe app built around four core systems:

1. recipe browsing and recipe detail
2. shopping list management
3. pantry management
4. unit-aware quantity math

If you understand these files:

- [public/recipe.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/recipe.php)
- [public/shopping_list.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/shopping_list.php)
- [public/pantry.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/public/pantry.php)
- [private/unit-helpers.php](/C:/Users/cobel/OneDrive/Desktop/Web289/Cookventory_Primary/private/unit-helpers.php)

you will understand most of the app’s real behavior.
