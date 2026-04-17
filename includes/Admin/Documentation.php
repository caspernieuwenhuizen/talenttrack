<?php
namespace TT\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

class Documentation {
    public static function init() {}

    public static function render_page() {
        $role = $_GET['role'] ?? 'admin';
        ?>
        <div class="wrap">
            <h1>TalentTrack — Help &amp; Documentation</h1>
            <nav class="nav-tab-wrapper">
                <a href="?page=tt-docs&role=admin" class="nav-tab <?php echo $role === 'admin' ? 'nav-tab-active' : ''; ?>">Admin / Head of Development</a>
                <a href="?page=tt-docs&role=coach" class="nav-tab <?php echo $role === 'coach' ? 'nav-tab-active' : ''; ?>">Coach Guide</a>
                <a href="?page=tt-docs&role=player" class="nav-tab <?php echo $role === 'player' ? 'nav-tab-active' : ''; ?>">Player Guide</a>
            </nav>
            <div style="max-width:800px;margin-top:20px;line-height:1.8;">
            <?php
            switch ( $role ) {
                case 'admin':  self::guide_admin(); break;
                case 'coach':  self::guide_coach(); break;
                case 'player': self::guide_player(); break;
            }
            ?>
            </div>
        </div>
        <?php
    }

    private static function section( $title, $content ) {
        echo '<details style="margin-bottom:12px;border:1px solid #ddd;border-radius:4px;">';
        echo '<summary style="cursor:pointer;padding:12px 16px;font-weight:600;background:#f9f9f9;">' . esc_html( $title ) . '</summary>';
        echo '<div style="padding:12px 16px;">' . wp_kses_post( $content ) . '</div></details>';
    }

    private static function guide_admin() {
        echo '<h2>Administrator / Head of Development Guide</h2>';
        self::section( '1. Initial Setup', '
            <ol>
                <li>Go to <strong>TalentTrack → Configuration</strong>.</li>
                <li>Visit each tab and review/edit the defaults: Evaluation Categories, Evaluation Types, Positions, Preferred Foot, Age Groups, Goal Statuses, Goal Priorities, Attendance Statuses.</li>
                <li>Each list supports <strong>full CRUD</strong> — add, edit, or delete any item individually from its own tab.</li>
                <li>In the <strong>Rating Scale</strong> tab, set the minimum, maximum, and step for all ratings.</li>
                <li>In <strong>Branding</strong>, upload your academy logo, set primary/secondary colors and academy name.</li>
                <li>In <strong>Reports</strong>, configure composite score weights for the Development Score.</li>
                <li>In <strong>System</strong>, toggle which modules are enabled.</li>
            </ol>
        ');
        self::section( '2. Managing Teams', '<ol><li><strong>TalentTrack → Teams → Add New</strong>.</li><li>Enter name, age group, assign a head coach (WP user), add notes.</li><li>Edit or delete via the action links.</li></ol>' );
        self::section( '3. Managing Players', '<ol><li><strong>TalentTrack → Players → Add New</strong>.</li><li>Fill in first/last name, DOB, nationality, physical measurements, preferred foot, positions.</li><li>Assign to a team, upload a photo, add guardian contact, link a WP user account.</li><li>Click <strong>View</strong> on any player to see their profile with radar chart, evaluations, goals.</li></ol>' );
        self::section( '4. Evaluations', '<ol><li><strong>TalentTrack → Evaluations → Add New</strong>.</li><li>Select player, evaluation type, and date.</li><li>If the type requires match details, additional fields (opponent, competition, result, home/away, minutes) appear automatically.</li><li>Rate each category using the configured scale.</li><li>Save — the evaluation is visualized as a radar chart.</li></ol>' );
        self::section( '5. Training Sessions & Attendance', '<ol><li><strong>TalentTrack → Sessions → Add New</strong>.</li><li>Enter title, date, team, location.</li><li>Below the form, set each player\'s attendance status (Present, Absent, Late, etc.).</li></ol>' );
        self::section( '6. Goals', '<ol><li><strong>TalentTrack → Goals → Add New</strong>.</li><li>Select player, set title/description, priority, status, due date.</li></ol>' );
        self::section( '7. Reports', '<ol><li><strong>TalentTrack → Reports</strong>.</li><li>Pick report type, apply filters, click <strong>Run Report</strong>.</li><li>Save frequently-used filter combinations as presets.</li></ol>' );
        self::section( '8. Frontend Dashboard', '<ol><li>Create a page and add <code>[talenttrack_dashboard]</code>.</li><li>The shortcode adapts based on the logged-in user\'s role.</li></ol>' );
    }

    private static function guide_coach() {
        echo '<h2>Coach Guide</h2>';
        self::section( '1. Logging In', '<ol><li>Log in with the credentials your admin provided.</li><li>Access the frontend Coach Dashboard, or use the WP admin panel.</li></ol>' );
        self::section( '2. Viewing Your Team Roster', '<ol><li>From the frontend dashboard, click the <strong>My Team</strong> tab.</li><li>Click a player to view full profile, evaluation history, and radar chart.</li></ol>' );
        self::section( '3. Adding a Training Evaluation', '<ol><li>Click <strong>New Evaluation</strong>.</li><li>Select player, set type to Training, rate each category, add notes, save.</li></ol>' );
        self::section( '4. Adding a Match Evaluation', '<ol><li>Click <strong>New Evaluation</strong>, select Match type.</li><li>Fill in opponent, competition, result, home/away, minutes played.</li><li>Rate each category and save.</li></ol>' );
        self::section( '5. Recording Sessions & Attendance', '<ol><li>Go to <strong>Sessions → New Session</strong>.</li><li>Enter session details and mark each player\'s attendance.</li></ol>' );
        self::section( '6. Managing Goals', '<ol><li>Add a goal from the <strong>Manage Goals</strong> tab.</li><li>Update status inline as players progress.</li></ol>' );
        self::section( '7. Reading the Radar Chart', '<p>Each axis = one category. Further from center = higher rating. Multiple layers show progression over time.</p>' );
    }

    private static function guide_player() {
        echo '<h2>Player Guide</h2>';
        self::section( '1. Logging In', '<ol><li>Log in with the credentials provided by your academy.</li><li>Navigate to the dashboard page.</li></ol>' );
        self::section( '2. Your Profile', '<p>The <strong>Overview</strong> tab shows your personal info and latest radar chart.</p>' );
        self::section( '3. Your Evaluations', '<p>The <strong>Evaluations</strong> tab lists every evaluation recorded for you.</p>' );
        self::section( '4. Reading Your Radar Chart', '<p>Each axis represents a skill category. Multiple colored shapes show different dates — track your growth.</p>' );
        self::section( '5. Your Goals', '<p>The <strong>Goals</strong> tab shows development goals your coaches have assigned you.</p>' );
        self::section( '6. Attendance', '<p>See your full session attendance history.</p>' );
        self::section( '7. Progress', '<p>Visualize your development trajectory with up to 5 evaluations overlaid.</p>' );
    }
}
