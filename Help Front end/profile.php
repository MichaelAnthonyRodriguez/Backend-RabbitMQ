<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile</title>
    <link rel="stylesheet" href="CSS/Profile.css">
</head>
<body>
    <ul>
        <li><a href="dashboard.php">Home</a></li>
        <li><a href="movies.php">Movies</a></li>
        <li><a class="active" href="profile.php">Profile</a></li>
        <li class="logout" style="float:right;"><a href="logout.php">Logout</a></li>
    </ul>

    <div class="profile-container">
        <div class="profile-header">
            <img src="https://via.placeholder.com/150" alt="Profile" class="profile-pic" id="profilePic">
            <h1 id="username">Loading...</h1>
            <p id="memberSince">Member since 2023</p>
        </div>

        <div class="stats">
            <div class="stat-item">
                <div class="number" id="moviesRated">0</div>
                <div>Movies Rated</div>
            </div>
            <div class="stat-item">
                <div class="number" id="reviews">0</div>
                <div>Reviews</div>
            </div>
            <div class="stat-item">
                <div class="number" id="watchlist">0</div>
                <div>Watchlist</div>
            </div>
        </div>

        <div class="recent-activity">
            <h2>Recent Activity</h2>
            <div id="activityFeed">
                <!-- Activities will be added here by JavaScript -->
            </div>
        </div>
    </div>

    <script>
        // Mock data - Replace with actual API calls
        const PROFILE_API_URL = "https://api.yourmovieservice.com/profile";
        const ACTIVITY_API_URL = "https://api.yourmovieservice.com/activity";

        // Load profile data
        async function loadProfile() {
            try {
                // Replace with your actual API endpoint
                const response = await fetch(PROFILE_API_URL, {
                    headers: {
                        'Authorization': 'Bearer YOUR_ACCESS_TOKEN' // Add auth if needed
                    }
                });
                
                if (!response.ok) throw new Error("Failed to load profile");
                
                const profile = await response.json();
                
                // Update profile
                document.getElementById('username').textContent = profile.name;
                document.getElementById('profilePic').src = profile.imageUrl || 'https://via.placeholder.com/150';
                document.getElementById('memberSince').textContent = `Member since ${profile.joinDate}`;
                document.getElementById('moviesRated').textContent = profile.stats.moviesRated;
                document.getElementById('reviews').textContent = profile.stats.reviews;
                document.getElementById('watchlist').textContent = profile.stats.watchlist;
                
                // Load activity
                loadActivity();
                
            } catch (error) {
                console.error("Profile load error:", error);
                alert("Failed to load profile data");
            }
        }

        // Load activity feed
        async function loadActivity() {
            try {
                const response = await fetch(ACTIVITY_API_URL);
                if (!response.ok) throw new Error("Failed to load activity");
                
                const activities = await response.json();
                const activityFeed = document.getElementById('activityFeed');
                
                activities.forEach(activity => {
                    const activityItem = document.createElement('div');
                    activityItem.className = 'activity-item';
                    activityItem.innerHTML = `
                        <img src="${activity.moviePoster}" onerror="this.src='https://via.placeholder.com/60x90'">
                        <div>
                            <strong>${activity.action} ${activity.movieTitle}</strong>
                            <p>${activity.comment || ''}</p>
                            <small>${new Date(activity.date).toLocaleDateString()}</small>
                        </div>
                    `;
                    activityFeed.appendChild(activityItem);
                });
                
            } catch (error) {
                console.error("Activity load error:", error);
            }
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', loadProfile);
    </script>
</body>
</html>
