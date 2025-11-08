function showPage(pageName) {
  // Hide all pages
  document.querySelectorAll(".page-content").forEach((page) => {
    page.classList.remove("active");
  });

  // Remove active class from all nav items
  document.querySelectorAll(".nav-item").forEach((item) => {
    item.classList.remove("active");
  });

  // Show selected page
  document.getElementById(pageName).classList.add("active");

  // Add active class to clicked nav item
  event.target.closest(".nav-item").classList.add("active");
}

function toggleMobileMenu() {
  const sidebar = document.getElementById("sidebar");
  const overlay = document.querySelector(".mobile-overlay");
  sidebar.classList.toggle("mobile-open");
  overlay.classList.toggle("active");
}

function closeMobileMenu() {
  const sidebar = document.getElementById("sidebar");
  const overlay = document.querySelector(".mobile-overlay");
  sidebar.classList.remove("mobile-open");
  overlay.classList.remove("active");
}

// Auto-scroll terminal output to bottom
const terminalOutput = document.querySelector(".terminal-output");
if (terminalOutput) {
  terminalOutput.scrollTop = terminalOutput.scrollHeight;
}

// Close mobile menu when resizing to desktop
window.addEventListener("resize", function () {
  if (window.innerWidth > 768) {
    closeMobileMenu();
  }
});
