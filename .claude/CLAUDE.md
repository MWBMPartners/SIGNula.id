I would like to create a "Universal", all-in-one, single login user account system that will be used across a variety of services (both web based and mobile apps) to allow for "universal" access among other things. This "Universal" all-in-one single login/single sign-in system is to be developed in/using PHP, MySQL/MariaDB using MySQLI as the connection method from within PHP, and using MySQL PRepared statements to manage common queries.

The idea behind the system (named "SIGNula", with the account being called a "SIGNula ID@) is that it will have a "local"/internal user account within the system to uniquely identify a user, but iti will also have support for the following features:

* Multi-factor authentication, including support for authenticator aapps like Microsoft Authenticator, Google Authenticator etc, including support for password-free login/verifications offered by these MFA apps using their push notifications. INCLUDING support for recovery keys
	MFA via Password-less login should also be supported via a secure tokenised link via email, One-Time-Code via email etc which expires within a short (internal DB configurable) period of time (say 15-30 minutes as a default?)
* Integration of the linkage of the following third-party account systems, including but not limited to:
	- Google Account (Personal as well as Workspace)
	- Microsoft Account (Personal as well as Microsoft 365 Personal/Work/School)
	- Apple ID/Apple Account
	- Facebook/Instagram (Meta) Account
	- LinkedIn
	- LastPass account
	- Yahoo! Account
	- WordPress Account
	- Amazon Account
	- PayPal Account
	- OpenID
* Biometric login support, including mechanisms from supported devices/services such as (but not limited to):
	- Apple TouchID/Apple FaceID
	- Microsoft Windows Hello
	- Android biometrics
* PassKey support
* Password-less login using secure, tokenised email link (that expires within a specific, short, period of time)

The idea is that this system is would be all-round system, but also extremely secure in todays online security focused era.

This "account system" must then be able to be integrated with other web services/apps that we will be developing internally (first-part service), but can also be used by third-party services.
Some of these services that would integrate SIGNula will be offered for free, but COULD also allow for the abaility to offer paid services/tiers. As such, SIGNula would need to be able to support storage of customer/client/user settings cusstomisations, paid tiers and features available within those tiers, where the "universal" SIGNula system is used to link all this together and also allow the same user account and paid for services to be used across various platforms (browser-based, various app platforms etc).

The idea is that the integration of SIGNula with these separate apps/services will be via a JSON-based API to exchange authentictaion/info.comments/calls etc (possible a RESTful API would be the standised method for this), but thaat this API must be FULLY SECURE, following current and future industry standards for good practices. There shoulod also be a web-based mechanism (as well as via the integrated app/service UIs, again maybe via API Calls) to manage their user accounts such as:
* Link to third-party account services (as described above)
* change/reset email addresses/passwords
* Enable/Disabled MFA options
* Manage PassKey (Generate, disable/remove, regenerate etc.)

This account manaagement should be possible within SIGNulo's web interface OR via each integrated service's own website/domains. Status of login/out status shsould be managed/maintained, and this should be appropriately planned for/considerd for the various platforms (web-browser based, native apps), either this is cookies, sessions or any appropriate method, even if a combination. 

All user acount activity (failed login attempts, successul login attempts, account creation, account modification, account linkages etc) should be logged into its own tale (such as tblActivityLog) which should also log visitor IP Address (support for IPv4 & IPv6), useragent etc. This is to use for debugging/support aand security auditing purposes.


========================
*TECH STACK and specific instructions*
========================
Please use the following technologies for development of the entire 'SINGula.ID' project
* PHP (minimum PHP 8.3, but aiming for PHP 8.4 for longevity purposes)
	* PHP code should
		* Use PHP Predefined Constants wherever possible to aid with host platform neutrality (ie, use DIRECTORY_SEPARATOR instead of '/' in paths, PHP_EOL etc)
		* Full notation for code blocks such as (but not limited to) IF statements, no shortened notation
		* be indented appropriately with detailed inline comments/annotations to assist with code readibility, understanding, debugging and future learning/maintenance of code
			* Comments Can/should provide links to external (preferrably official) documentation for further guidance. 
		* A modular development structure
		* Minimise code duplication but grouping shared code and reusing them via PHP REQUIRE, REQUIRE_ONCE, INCLUDE & INCLUDE_ONCE
		  appropriate checks and error handling for REQUIRED and INCLUDED components should be done to avoid errors should files not exist/not be retrievable
		* Provide "switches" (perhaps via URL Parameters or other means) to enable detailed PHP error visiblity by use of a URL Param such as &debug=true or similar (for dev purposes only). Such errors should be chosable (also maybe via URL params/session variables) as to if errors are presented on screen in browser rendered pages, or Console.
		* log PHP errors/notifictions/warnings (including severity, PHP error code, PHP Error title, error detail, error locaction file and line, backtrace etc) to a table in the backend database along with any other errors from components and requested page URL and request headers to assist with debugging/trouble shooting.
		* Log general application activity (not just errors/notifications/warnings/problems) to assist with future development and prooduct support

* MySQL Backend Database (connectin
	* Interactions between PHP & MySQL via MySQLi only
	* Use Prepared Statements for all SQL run in PHP, to assist with standardising and secure query management.
	* Backend database to be used for ALL settings and config
	* Settings should also be stored in the database, including credentials (keys, secrets etc) for third-party services/libraries (like MS365 etc)
	* any confidential secrets/keys (labelled as "isSensitive") must be cryptographically encrypted before entry into the database to ensure data security. Encryption of these confidential info should also use SALT for added security. When retrieving these fields, they should also be decrypted for use. This should all follow the latesst and best online/cyber security bests practice

* HTML5
	* Must be fully HTML5 compliant, and using modern, up-to-the minute, HTML5 elements, standards and methodologies
	* For bot projection of user account creation/user interactions, where appropriate, the following services should be useable
		- CloudFlare Turnstile
		- ReCapture
* Payments (includidng subscriptions) using PayPal, but support should also be offered including Apple Pay, Google Pay etc. Payments in Crypto Currencies should also be supported, and include the ability to discount payments made via configurable payment platforms.





------------------------
 *Used Domain Names*
------------------------

We currently "own" the following domains:
	* SIGNulo.com
	* SIGNulo.id
	
The idea is SIGNulo.com can be used for public facing info/promotion/marketing (as in a "shop window") type mechanism.

Whereas SIGNulo.id is used for day-to-day interactions, sign-in/sign-out, account management "Hub", API etc (ie more behind the scenes), via sub-domains. with the root www.SIGNulo.id being a redirect to the public-facing "shop windoe" sIGNulo.com
	

========================	
*SPECIFIC REQUIREMENTS*
========================

------------------------	
*Overall*
------------------------
* While this will be hosted on Dreamhost shared hosting initially, please include flexibility for hosting on other PHP/MySQL supporting platforms, as such please use PHP constants and predefined constants wherever possible for platform neutrality/flexibility.
* Given the project will (at least) initially be hosted on Dreamhost's Shared Hosting platform, access to CLI is not possible, as such access to tools like Composer cannot be relied upon. This means any third-party libraries that need to be installed/self-hosted (and cant just be used via referencing CDNs) need to be specifically called out and hosted on local server.



------------------------
	*Web Page Design*
------------------------
* In addition to having pre-supplied templates emails, It would also be useful, and user-friendly to have the ability for a user/admin to design their own Templates. Again, this should ideally be supported by the system using SIGNulo for account management, but we should like to offer a native/fallback for those who want to solely rely on SIGNulo more than others

------------------------
	*Web Page Design*
------------------------
* Web pages should be fully responsive, and accommodate all screen sizes & orientations
* Should also be compliant with all current standards
* Should take accessibilty into WCAG compliant to support users with impairements, including a toggle for colour blind users and screenreader support.
* Fully HTML5 compliant and use the latest and greatest HTML5 features, whilst also "gracefully" falling back for browsers that do not support them.
* Styling of the UI should use modern CSS including CSS 3+ and can make use of modern/advanced switches and even animations.
* Pages should indicate to the user that they're logged in, by showing something in the top-right corner (as is commonly the norm in the industry).
  This should display
	* User avatar (in this priority order: MS365/Google Workspace etc > Locally stored profile picture from tblUSers in database > Gravatar > default/placeholder avatar)
	* Name of user
	* A means to change user profile settings (possible via drop-down menu when clicking on Name and Avatar)
* ALL config (with the exception of database connection credentials) should be stored in the Backend MySQL Database (tblSettings table).
* Some settings, like MS365/Google Workspace etc credentials should also be able to be stored in a private location on the server as a fallback.
* It should also be possible for new settings to be added at any time by a Global Admin using the Web UI, and minimised requirement to have to manually update PHP Code too much to take these new settings to account.
* Values for settings marked as 'isSensitive' (as well as fields storing ANY user passwords) should be appropriately cryptographically encrypted/hashed to current industry standards, also using a salt for increased security, to ensure these are safe/secure - but also allow them to be internally decrypted for use as/when required.
* Whilst PHP will be used, the end user should not see this even in places like webpage/form/processor URLs. Not only is this unprofessional but it could also be a security risk as it exposes to potential attackers that PHP is used. Therefore all URLs should be visible as /something/somepage. This can perhaps be achieved by either a router (managed/tored in SQL database), or by simply ensuring the content is in a a location like /something/somepage/index.php (as in the case of the above example)
* Appropriate user-facing error handling should also be implemented, as well as backend error handling for server-side PHP scripts and log these into the Activity Log for later development and fixing by the development and Global Admin teams.
* Form fields (for ALL apps) should
	* Have dynamic field validation wherever possible
	* Use datepickers to ensure consistent format for entered dates.
	* Use ReCaptcha or CloudFlare Turnstile, provided the relevant API Keys are provided (preferred backend database, but fallback in auth.php). If no API Keys provided dont use these technologies for form submission.
* Web pages should also be designed to support use as a WebApp across all supported platforms and browsers, including but not limited to use as a PWA. This can be used for an initial web app until native apps are developed.

Beta & alpha versions for each app will be placed in beta_html & alpha_html folders (as opposed to public_html for "fully released versions"
Can we also consider implementing access control for alpha and beta versions of app to limit who's can access.

For further flexibility, some future features may only be accessible via a payment (one-off or subscription). Therefore a mechanism for managing multi-tier payment/subscription levels shoold also be included, with payment accepted via PayPal (also via Apple Pay or Google Pay on supported platforms/devices), and payment records, status and access availability needs to also all be stored in the backend database

All code (including SQL) should be fully/completely commented/annotated in detail, to assist with understanding of code and functionality, make code readable and also assist with future learning and debugging. Code should also be properly indented for improved human readability. Happy to use emojis in comments to help "call-out" sections of comments. 

We would also like to use AJAX where possible to dynamically load/change content to avoid entire page refreshes. However use of AJAX should not be a blocker if a browser doesnt support/have javascript/AJAX enabled. it should still be possible to use everything in the portal/sub-apps without AJAX
Please use Bootstrap, and other technologies/libraries (FontAwesome, JQuery etc) to achieve the required functionality, but please note the project will be hosted on Dreamhost Shared Hosting, so any libraries will need to be manually downloaded and uploaded as Compile and similar functionality is not available. Happy to use libraries from CDNs as a priority, but would be good to have a fallback to selfhosted should CDNs be down.

Any graphics/icons should be in SVG (Vector) to improve scalability and should offer a Base64 encoded fallback for browsers/clients that do not support SVG. (SVG support should be detected dynamically wherever possible). Animated SVG (vector) are also acceptible for icons

We're trying to achieve wide compatibility and a user friendly feel, especially some users may not be computer savvy.

With this in mind, we're looking for a modular design/development structure with a focus on functionality, security, data integrity as well as flexibility/scalability

Primary development environment will be on Mac OS (Windows occassionally) with Visual Studio Code as our IDE where we also intend to use the ftp-sync extension. We would like to have a GitHub repo to manage the development and staging of the project. please also suggest the best way to setup and manage this project within a GitHub repo

For security reasons, can includes, require etc be placed outside of web accessible directories. We want to ensure only the necessarily files for public access in web-accesssible folders. Any directories used for private (not web accessible files) should have a underscore '_' prefix.



------------------------
	*Terms of Use & Data Policy*
------------------------
As we use and collect (limited data), please also generate the following:

* Terms of Use Policy
* Copyright Notice(s)
* Data Protection & Cookies Policy
  including for compliance with
	* GDPR
	UK GDPR + DPA 2018
	* CCPA / CPRA / VCDPA / CPA / CTDPA / UCPA
	* HIPAA
	* COPPA
	* PIPEDA (Canada)
	* LGPD (Brazil)
	* Privacy Act 1988 (Australia) + Notifiable Data Breaches scheme
	* Privacy Act 2000 (New Zealand)
	* DPDP Act (India)
	* PIPL (China)
	* CSL & DSL (China)
	* APPI (Japan)
	* PIPA (South Korea)
	* POPIA (South Africa)

	Please also build in functionality to manage any data to comply with the above data protection laws.


Whilst i acknowledge that this is somewhat of a sophisticated project, i would like to prioritise the internal account with MFA functionality, email support and API features to allow for integration with our future (internal apps). Other proposed functionality can follow later in additional milestone "releases", that we will follow up on once initial useable version is implemented

Please generate a README.md and a project roadmap tracking facility (perhaps via a PROJECT_PROGRESS.md) for the GitHub repo. Please also guide on setting up project tracking/management and release workflows within GitHub repo.

Please generate the code to implement this project. Perhaps we should break it down into stages for development? Feel free to break down the code generation into smaller chunks/phases to meet response limits