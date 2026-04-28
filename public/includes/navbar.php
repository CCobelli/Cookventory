<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../private/role-helpers.php';
?>

<header class="cv-header">
  <div class="cv-topbar">
    <nav id="cv-nav" class="cv-nav" aria-label="Primary navigation">
      <div class="cv-nav-top">
        <a class="cv-logo" href="index.php" aria-label="Cookventory Home">Cookventory</a>

        <div class="cv-nav-right">
          <?php if (isset($_SESSION['user_id'])): ?>
            <div class="cv-burger-wrap cv-account-wrap">
              <button class="cv-burger cv-account-toggle" type="button" aria-label="Open account menu" aria-expanded="false" aria-controls="cv-utility-menu">
                <span class="cv-account-name"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                <span class="cv-account-arrow" aria-hidden="true">&#9662;</span>
              </button>

              <div id="cv-utility-menu" class="cv-burger-menu" aria-label="Quick actions">
                <a href="pantry.php">Pantry</a>
                <a href="shopping_list.php">Shopping List</a>
                <a href="saved_recipes.php">Saved Recipes</a>
                <a href="my_recipes.php">My Recipes</a>
                <?php if (isAdminUser()): ?>
                  <a href="usability_results.php">Usability Results</a>
                  <a href="admin.php">Admin Panel</a>
                <?php endif; ?>
                <a href="logout.php">Logout</a>
              </div>
            </div>
          <?php else: ?>
            <a class="cv-navlink cv-cta" href="login.php">Login</a>
          <?php endif; ?>
        </div>
      </div>

      <div class="cv-nav-bottom">
        <div class="cv-nav-left">
          <div class="cv-dropdown">
            <button class="cv-navlink cv-dropbtn" type="button" aria-expanded="false" aria-haspopup="true">
              Recipes <span aria-hidden="true">&#9662;</span>
            </button>

            <div class="cv-dropmenu" role="menu" aria-label="Recipes menu">
              <a href="recipes.php" role="menuitem">All Recipes</a>
              <a href="recipes.php?quick=1" role="menuitem">Under 30 min</a>
              <a href="recipes.php?course_name=Breakfast" role="menuitem">Breakfast</a>
              <a href="recipes.php?course_name=Lunch" role="menuitem">Lunch</a>
              <a href="recipes.php?course_name=Dinner" role="menuitem">Dinner</a>
              <a href="recipes.php?course_name=Dessert" role="menuitem">Dessert</a>
            </div>
          </div>

          <div class="cv-dropdown">
            <button class="cv-navlink cv-dropbtn" type="button" aria-expanded="false" aria-haspopup="true">
              Cuisine <span aria-hidden="true">&#9662;</span>
            </button>

            <div class="cv-dropmenu" role="menu" aria-label="Cuisine menu">
              <a href="recipes.php?cuisine_name=Italian" role="menuitem">Italian</a>
              <a href="recipes.php?cuisine_name=French" role="menuitem">French</a>
              <a href="recipes.php?cuisine_name=Japanese" role="menuitem">Japanese</a>
              <a href="recipes.php?cuisine_name=Mexican" role="menuitem">Mexican</a>
            </div>
          </div>

          <a class="cv-navlink" href="recipes.php">Popular</a>
          <?php if (isset($_SESSION['user_id'])): ?>
            <a class="cv-navlink cv-cta" href="create_recipe.php">Create Recipe</a>
          <?php endif; ?>
        </div>

        <button class="cv-mobile-search-toggle" type="button" aria-label="Open search" aria-expanded="false" aria-controls="cv-navbar-search">
          <span class="cv-mobile-search-icon" aria-hidden="true">&#128269;</span>
        </button>

        <form id="cv-navbar-search" class="cv-search" action="recipes.php" method="GET" role="search">
          <input class="cv-search-input" type="search" name="q" placeholder="Find a recipe..." aria-label="Search recipes">
          <button class="cv-search-btn" type="submit">Search</button>
        </form>
      </div>
    </nav>
  </div>
</header>





