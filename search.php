// Search for, browse, and see details on films:
// When entering a search into an input, return:
// select * from movies where (search term is in the movie title)
// When selecting a genre of movie, return:
// select * from movies where (genre == selected)
<?php
  $genreSearchQuery = "SELECT * from movies where genre = ?";
  $titleSearchQuery = "SELECT * from movies where title LIKE ?";
?>
