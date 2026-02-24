<?php
/**
 * ============================================================================
 * ©️ SIGNula Copyright Notice
 * ============================================================================
 * Copyright and intellectual property information
 *
 * @package    SIGNula
 * @subpackage Legal
 * @version    1.0.0
 * ============================================================================
 */

// Page configuration
$pageTitle = 'Copyright Notice - SIGNula';
$metaDescription = 'Copyright and intellectual property information for SIGNula services.';
$currentPage = '';
$ogImage = '/assets/images/og-legal.png';

// Include header component
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-header.php';

$currentYear = date('Y');
?>

<!-- ========================================================================
     LEGAL HERO SECTION
     ======================================================================== -->
<section class="bg-light py-5">
    <div class="container">
        <div class="row">
            <div class="col-lg-8 mx-auto text-center">
                <h1 class="display-4 fw-bold mb-3">
                    <i class="fas fa-copyright me-3"></i>Copyright Notice
                </h1>
                <p class="lead text-secondary">
                    © <?php echo $currentYear; ?> MWBMPartners. All Rights Reserved.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- ========================================================================
     COPYRIGHT CONTENT
     ======================================================================== -->
<section class="section-padding">
    <div class="container">
        <div class="row">
            <div class="col-lg-10 mx-auto">
                <div class="legal-content">
                    <!-- Copyright Statement -->
                    <section class="mb-5">
                        <h2 class="h3 mb-3">Copyright Statement</h2>
                        <div class="alert alert-primary">
                            <p class="mb-0">
                                <strong>All content on this website, including but not limited to text, graphics, logos, icons, images, audio clips, digital downloads, data compilations, and software, is the property of MWBMPartners or its content suppliers and is protected by international copyright laws.</strong>
                            </p>
                        </div>
                    </section>

                    <!-- Ownership -->
                    <section class="mb-5">
                        <h2 class="h3 mb-3">Ownership</h2>
                        <p>
                            The compilation of all content on this site is the exclusive property of MWBMPartners and is protected by U.S. and international copyright laws. All software used on this site is the property of MWBMPartners or its software suppliers and is protected by U.S. and international copyright laws.
                        </p>

                        <h4 class="h5 mt-4 mb-3">What We Own</h4>
                        <ul>
                            <li><strong>SIGNula Software:</strong> All source code, algorithms, and functionality</li>
                            <li><strong>Website Content:</strong> Text, documentation, articles, and guides</li>
                            <li><strong>Design Elements:</strong> Graphics, logos, page headers, button icons, scripts</li>
                            <li><strong>Trademarks:</strong> SIGNula name, logos, and related marks</li>
                            <li><strong>Database Rights:</strong> Data structure and organization</li>
                        </ul>
                    </section>

                    <!-- Permitted Uses -->
                    <section class="mb-5">
                        <h2 class="h3 mb-3">Permitted Uses</h2>
                        <p>You may:</p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="card border-success">
                                    <div class="card-body">
                                        <h5 class="card-title text-success"><i class="fas fa-check-circle me-2"></i>Allowed</h5>
                                        <ul>
                                            <li>Use our services according to our <a href="/legal/terms">Terms of Service</a></li>
                                            <li>View and download content for personal, non-commercial use</li>
                                            <li>Share links to our website</li>
                                            <li>Use our API according to our API documentation</li>
                                            <li>Quote brief excerpts with proper attribution</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card border-danger">
                                    <div class="card-body">
                                        <h5 class="card-title text-danger"><i class="fas fa-times-circle me-2"></i>Prohibited</h5>
                                        <ul>
                                            <li>Reproduce, duplicate, or copy content without permission</li>
                                            <li>Modify, adapt, or create derivative works</li>
                                            <li>Distribute or publicly display content</li>
                                            <li>Reverse engineer our software</li>
                                            <li>Remove copyright notices or attributions</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Trademarks -->
                    <section class="mb-5">
                        <h2 class="h3 mb-3">Trademarks</h2>
                        <p>
                            <strong>SIGNula</strong>, the SIGNula logo, and other marks indicated on our website are trademarks or registered trademarks of MWBMPartners. Other trademarks, product names, and company names mentioned on this website are the property of their respective owners.
                        </p>
                        <p>
                            You may not use our trademarks without prior written permission from MWBMPartners. Unauthorized use may constitute trademark infringement and unfair competition in violation of federal and state laws.
                        </p>

                        <h4 class="h5 mt-4 mb-3">Trademark Usage Guidelines</h4>
                        <p>If you wish to use our trademarks, you must:</p>
                        <ul>
                            <li>Request written permission via email to <a href="mailto:legal@signula.com">legal@signula.com</a></li>
                            <li>Use marks exactly as specified by us</li>
                            <li>Include appropriate trademark symbols (™ or ®)</li>
                            <li>Not suggest endorsement or affiliation without authorization</li>
                            <li>Not use marks in a way that could confuse consumers</li>
                        </ul>
                    </section>

                    <!-- Third-Party Content -->
                    <section class="mb-5">
                        <h2 class="h3 mb-3">Third-Party Content</h2>
                        <p>
                            Our website may include content, products, or services from third parties. We do not control or endorse such content, and it remains the property of its respective owners.
                        </p>

                        <h4 class="h5 mt-4 mb-3">Third-Party Licenses</h4>
                        <p>We use the following open-source and third-party components:</p>
                        <ul>
                            <li><strong>Bootstrap</strong> - MIT License</li>
                            <li><strong>Font Awesome</strong> - Font Awesome Free License</li>
                            <li><strong>jQuery</strong> - MIT License</li>
                        </ul>
                        <p><small class="text-muted">Full license information available in our software documentation.</small></p>
                    </section>

                    <!-- DMCA -->
                    <section class="mb-5">
                        <h2 class="h3 mb-3">Digital Millennium Copyright Act (DMCA)</h2>
                        <p>
                            MWBMPartners respects the intellectual property rights of others. If you believe that your copyrighted work has been copied in a way that constitutes copyright infringement, please provide our Copyright Agent with the following information:
                        </p>

                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">DMCA Notice Requirements</h5>
                                <ol>
                                    <li>A physical or electronic signature of the copyright owner or authorized representative</li>
                                    <li>Identification of the copyrighted work claimed to have been infringed</li>
                                    <li>Identification of the material that is claimed to be infringing, with sufficient detail to locate it</li>
                                    <li>Your contact information (address, telephone number, and email address)</li>
                                    <li>A statement that you have a good faith belief that use of the material is not authorized</li>
                                    <li>A statement, under penalty of perjury, that the information provided is accurate and you are authorized to act on behalf of the copyright owner</li>
                                </ol>

                                <div class="alert alert-info mt-3 mb-0">
                                    <strong>DMCA Agent:</strong><br>
                                    Email: <a href="mailto:dmca@signula.com">dmca@signula.com</a><br>
                                    Mail: MWBMPartners, DMCA Agent, [Address]
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Attribution -->
                    <section class="mb-5">
                        <h2 class="h3 mb-3">Attribution Requirements</h2>
                        <p>
                            If you are granted permission to use our content, you must provide clear attribution:
                        </p>
                        <div class="card bg-light">
                            <div class="card-body">
                                <p class="mb-2"><strong>Proper Attribution Format:</strong></p>
                                <code>
                                    "Content provided by SIGNula (https://signula.com) © <?php echo $currentYear; ?> MWBMPartners. Used with permission."
                                </code>
                            </div>
                        </div>
                    </section>

                    <!-- Violations -->
                    <section class="mb-5">
                        <h2 class="h3 mb-3">Reporting Copyright Violations</h2>
                        <p>
                            If you believe someone is infringing on your intellectual property rights through our platform, please report it immediately:
                        </p>
                        <div class="card">
                            <div class="card-body">
                                <p class="mb-2"><strong>Email:</strong> <a href="mailto:legal@signula.com">legal@signula.com</a></p>
                                <p class="mb-2"><strong>Subject Line:</strong> "Copyright Infringement Report"</p>
                                <p class="mb-0"><strong>Include:</strong> Detailed description, evidence, and contact information</p>
                            </div>
                        </div>
                    </section>

                    <!-- Contact -->
                    <section class="mb-5">
                        <h2 class="h3 mb-3">Licensing Inquiries</h2>
                        <p>
                            For licensing inquiries, partnership opportunities, or permission requests:
                        </p>
                        <div class="card">
                            <div class="card-body">
                                <p class="mb-2"><strong>Email:</strong> <a href="mailto:licensing@signula.com">licensing@signula.com</a></p>
                                <p class="mb-2"><strong>Business Inquiries:</strong> <a href="mailto:business@signula.com">business@signula.com</a></p>
                                <p class="mb-0"><strong>Contact Form:</strong> <a href="/contact?type=partnership">Partnership Inquiry</a></p>
                            </div>
                        </div>
                    </section>

                    <!-- Related Policies -->
                    <div class="card bg-light">
                        <div class="card-body">
                            <h5 class="card-title">Related Policies</h5>
                            <ul class="mb-0">
                                <li><a href="/legal/terms">Terms of Service</a> - Legal agreement for using our services</li>
                                <li><a href="/legal/privacy">Privacy Policy</a> - How we handle your personal information</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Legal Notice -->
                    <div class="alert alert-warning mt-5">
                        <h5 class="alert-heading"><i class="fas fa-balance-scale me-2"></i>Legal Notice</h5>
                        <p class="mb-0">
                            Unauthorized reproduction, distribution, or use of copyrighted material may result in severe civil and criminal penalties, and will be prosecuted to the maximum extent possible under law.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<style>
.legal-content h2 {
    margin-top: 2rem;
    padding-top: 2rem;
    border-top: 2px solid var(--border-color);
}

.legal-content h2:first-child {
    margin-top: 0;
    padding-top: 0;
    border-top: none;
}
</style>

<?php
// Include footer component
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-footer.php';
?>
