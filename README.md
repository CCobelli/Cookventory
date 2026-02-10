# Cookventory

A web application for managing your kitchen inventory, recipes, and shopping lists. Track what's in your pantry, discover recipes you can make with what you have, and generate shopping lists for what you need.

## Features

- **Pantry Management** -- Add, track, and manage ingredients with quantities, units, and expiration dates
- **Recipe Storage** -- Save recipes with ingredients, step-by-step instructions, prep/cook times, and YouTube links
- **Categories** -- Organize recipes by protein type, diet, cuisine, and meal type
- **Shopping Lists** -- Keep track of ingredients you need to buy
- **Ratings & Reviews** -- Rate and review recipes

## Tech Stack

- **Frontend:** HTML, CSS, JavaScript
- **Backend:** PHP
- **Database:** MySQL / MariaDB

## Project Structure

```
Cookventory/
├── index.html              # Landing page
├── Pantry.html             # Pantry management page
├── script.js               # Frontend JavaScript
├── style.css               # Styles
├── test_db.php             # Database connection test
├── db/
│   └── schema.sql          # Full database schema + seed data
├── private/
│   └── db-connect.php      # Database connection (uses .env)
├── .env.example            # Template for environment variables
└── .gitignore
```

## Setup

### 1. Clone the repo

```bash
git clone https://github.com/Darv0n/Cookventory.git
cd Cookventory
```

### 2. Create your environment file

Copy the example and fill in your database credentials:

```bash
cp .env.example .env
```

Edit `.env` with your MySQL credentials:

```
DB_HOST=localhost
DB_USERNAME=your_username
DB_PASSWORD=your_password
DB_NAME=cookventory
```

### 3. Set up the database

Create the database and run the schema:

```bash
mysql -u your_username -p -e "CREATE DATABASE IF NOT EXISTS cookventory;"
mysql -u your_username -p cookventory < db/schema.sql
```

This creates all tables and inserts seed data (roles, units, categories).

### 4. Test the connection

```bash
php test_db.php
```

You should see "Database connection successful!" and a list of tables.

### 5. Run the app

Serve with any PHP-capable web server. For local development:

```bash
php -S localhost:8000
```

Then open http://localhost:8000 in your browser.

## Database Schema

The database uses a relational design with the following table groups:

| Group | Tables | Purpose |
|-------|--------|---------|
| Users | `user_usr`, `user_role_usrrol`, `role_rol` | User accounts and role-based access |
| Recipes | `recipe_rcp`, `recipe_step_stp`, `recipe_image_img` | Recipe details, steps, and images |
| Ingredients | `ingredient_ing`, `recipe_ingredient_rcping` | Ingredient master list and recipe-ingredient mapping |
| Categories | `category_cat`, `category_type_cty`, `recipe_category_rcpcat` | Recipe categorization (protein, diet, cuisine, meal type) |
| Pantry | `pantry_item_pan` | User's kitchen inventory |
| Shopping | `shopping_list_item_shp` | Shopping list items |
| Ratings | `rating_rat` | Recipe ratings and reviews |

## Contributing

1. Fork the repo
2. Create a feature branch (`git checkout -b feature/my-feature`)
3. Commit your changes (`git commit -m "Add my feature"`)
4. Push to the branch (`git push origin feature/my-feature`)
5. Open a Pull Request
