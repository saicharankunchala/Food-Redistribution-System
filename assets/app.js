const notificationsPanel = document.getElementById("notificationsPanel");
const notificationButton = document.getElementById("notificationButton");
const notificationCount = document.getElementById("notificationCount");
const notificationsList = document.getElementById("notificationsList");
const markNotificationsRead = document.getElementById("markNotificationsRead");
const detectLocationButton = document.getElementById("detectLocationButton");
const receiverLatitude = document.getElementById("receiverLatitude");
const receiverLongitude = document.getElementById("receiverLongitude");

if (notificationButton && notificationsPanel) {
    notificationButton.addEventListener("click", () => {
        notificationsPanel.classList.toggle("open");
    });
}

if (markNotificationsRead) {
    markNotificationsRead.addEventListener("click", async () => {
        await fetch("index.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded",
            },
            body: "action=mark_notifications_read",
        });
        refreshNotifications();
    });
}

async function refreshNotifications() {
    if (document.body.dataset.authenticated !== "yes" || !notificationsList || !notificationCount) {
        return;
    }

    try {
        const response = await fetch("index.php?action=notifications_json", {
            headers: { "X-Requested-With": "XMLHttpRequest" },
        });
        const data = await response.json();
        notificationCount.textContent = String(data.unread || 0);
        notificationsList.innerHTML = "";

        if (!data.notifications || data.notifications.length === 0) {
            notificationsList.innerHTML = "<p class='muted'>No notifications yet.</p>";
            return;
        }

        data.notifications.forEach((notification) => {
            const item = document.createElement("div");
            item.className = `notification-item ${notification.read ? "" : "unread"}`;
            const createdAt = new Date(notification.created_at).toLocaleString();
            item.innerHTML = `<p>${notification.message}</p><small>${createdAt}</small>`;
            notificationsList.appendChild(item);
        });
    } catch (error) {
        console.error("Notification refresh failed", error);
    }
}

if (document.body.dataset.authenticated === "yes") {
    refreshNotifications();
    setInterval(refreshNotifications, 10000);
}

if (detectLocationButton && receiverLatitude && receiverLongitude) {
    detectLocationButton.addEventListener("click", () => {
        if (!navigator.geolocation) {
            alert("Geolocation is not supported in this browser.");
            return;
        }

        navigator.geolocation.getCurrentPosition(
            (position) => {
                receiverLatitude.value = position.coords.latitude.toFixed(6);
                receiverLongitude.value = position.coords.longitude.toFixed(6);
            },
            () => {
                alert("Unable to detect location. Enter the coordinates manually.");
            }
        );
    });
}
