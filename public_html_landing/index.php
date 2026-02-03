<!-- File: /signula/public_html/index.php -->
<!-- Purpose: SIGNula Pre-Launch Teaser Landing Page -->
<!-- (C) 2025–present MWBM Partners Ltd (d/b/a MW Services) -->

<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="SIGNula — One Identity. All Access. A universal sign-in platform for the modern web." />
  <title>SIGNula — One Identity. All Access.</title>
  <link id="favicon" rel="icon" href="/assets/favicon-dark.svg" type="image/svg+xml" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" integrity="sha512-..." crossorigin="anonymous" referrerpolicy="no-referrer" />
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }
    html[data-theme="dark"] {
      --bg: linear-gradient(135deg, #0a1f44, #1d3557);
      --text: #fff;
      --primary: #1abc9c;
      --svg-stroke: #fff;
      --logo-png: url('data:image/png;base64,DARK_LOGO_BASE64');
    }
    html[data-theme="light"] {
      --bg: linear-gradient(135deg, #f0f4f8, #ffffff);
      --text: #111;
      --primary: #1abc9c;
      --svg-stroke: #111;
      --logo-png: url('data:image/png;base64,LIGHT_LOGO_BASE64');
    }
    body {
      font-family: 'Inter', sans-serif;
      background: var(--bg);
      color: var(--text);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      height: 100vh;
      padding: 2rem;
      text-align: center;
      transition: background 0.3s, color 0.3s;
    }
    .logo {
      width: 150px;
      height: 150px;
      margin-bottom: 2rem;
    }
    .logo img {
      display: none;
    }
    .logo-fallback {
      width: 150px;
      height: 150px;
      background-image: var(--logo-png);
      background-repeat: no-repeat;
      background-size: contain;
      background-position: center;
    }
    h1 {
      font-size: 2.5rem;
      margin-bottom: 1rem;
    }
    p.tagline {
      font-size: 1.2rem;
      font-weight: 300;
      margin-bottom: 2rem;
    }
    .notify-form {
      display: flex;
      flex-direction: column;
      width: 100%;
      max-width: 400px;
    }
    input[type="email"] {
      padding: 0.75rem;
      border: none;
      border-radius: 5px;
      margin-bottom: 1rem;
      font-size: 1rem;
    }
    button {
      padding: 0.75rem;
      background: var(--primary);
      color: #fff;
      border: none;
      border-radius: 5px;
      font-size: 1rem;
      cursor: pointer;
      transition: background 0.3s;
    }
    button:hover {
      background: #17a085;
    }
    .footer {
      position: absolute;
      bottom: 1rem;
      font-size: 0.85rem;
      color: #ccc;
	text-align: right;
    }
    .theme-toggle {
  position: absolute;
  top: 1rem;
  right: 1rem;
  background: none;
  border: none;
  color: var(--text);
  font-size: 1.75rem;
  cursor: pointer;
}

.theme-toggle .sun {
  display: none;
}

html[data-theme="light"] .theme-toggle .moon {
  display: none;
}

html[data-theme="light"] .theme-toggle .sun {
  display: inline;
}
    @media (max-width: 600px) {
      h1 {
        font-size: 2rem;
      }
      .logo {
        width: 100px;
        height: 100px;
      }
    }
  </style>
</head>
<body>
  <button class="theme-toggle" onclick="toggleTheme()" aria-label="Toggle dark/light theme">
  <i class="fas fa-moon moon"></i>
  <i class="fas fa-sun sun"></i>
</button>

  <!-- SVG logo with animated fallback PNG background -->
  <div class="logo">
    <svg class="logo" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
      <rect x="20" y="20" width="60" height="60" rx="10" ry="10" stroke="var(--svg-stroke)" stroke-width="5" fill="none" />
      <path d="M40 55 l10 10 l20 -20" stroke="var(--primary)" stroke-width="5" fill="none" stroke-linecap="round" stroke-linejoin="round">
        <animate attributeName="stroke-dashoffset" values="60;0" dur="1s" repeatCount="indefinite" />
      </path>
      <circle cx="10" cy="50" r="8" fill="var(--svg-stroke)">
        <animate attributeName="r" values="8;10;8" dur="1s" repeatCount="indefinite" />
      </circle>
      <rect x="18" y="45" width="15" height="10" fill="var(--svg-stroke)">
        <animate attributeName="x" values="18;16;18" dur="1s" repeatCount="indefinite" />
      </rect>
    </svg>
    <div class="logo-fallback"></div>
  </div>

  <h1>One Identity. All Access.</h1>
  <p class="tagline">SIGNula is your universal sign-in gateway—simple, secure, and seamless.</p>

  <!--<form class="notify-form" onsubmit="subscribe(event)">
    <input type="email" id="email" placeholder="Enter your email to get notified" required />
    <button type="submit">Notify Me</button>
  </form>-->

  <div class="footer">&copy; <?php if (date("Y")>"2025"){echo "2025–".date("Y");}else{echo date("Y");}?>  MWBM Partners Ltd</div>

  <script>
    function subscribe(e) {
      e.preventDefault();
      const email = document.getElementById('email').value;
      if (!email || !email.includes('@')) {
        alert('Please enter a valid email address.');
        return;
      }
      alert(`Thanks! We'll notify you at: ${email}`);
      document.getElementById('email').value = '';
    }

    function toggleTheme() {
      const html = document.documentElement;
      const isDark = html.getAttribute('data-theme') === 'dark';
      html.setAttribute('data-theme', isDark ? 'light' : 'dark');

      const favicon = document.getElementById('favicon');
      favicon.href = isDark ? '/assets/favicon-light.svg' : '/assets/favicon-dark.svg';
    }
  </script>
</body>
</html>