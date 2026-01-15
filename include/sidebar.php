<?php 
// include/sidebar.php
require_once __DIR__ . '/helpers.php'; 

// Current path (no query string)
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Function to check if user is a supervisor
if (!function_exists('is_user_supervisor')) {
    function is_user_supervisor() {
        // Check if user is logged in and has EmployeeID in session
        if (!isset($_SESSION['auth_user']['EmployeeID']) || empty($_SESSION['auth_user']['EmployeeID'])) {
            return false;
        }
        
        $employeeId = (int)$_SESSION['auth_user']['EmployeeID'];
        
        // Get database connection
        global $conn;
        
        if (!$conn) {
            return false;
        }
        
        try {
            // Check if this employee's ID exists in ANY supervisor column
            $sql = "SELECT CASE 
                           WHEN EXISTS (
                               SELECT 1 
                               FROM [dbPRFAssetMgt].[dbo].[Employees] 
                               WHERE SupervisorID_admin = ? 
                                  OR SupervisorID_technical = ? 
                                  OR SupervisorID_2ndLevel = ?
                           ) THEN 1 
                           ELSE 0 
                       END AS is_supervisor";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([$employeeId, $employeeId, $employeeId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return ($result && $result['is_supervisor'] == 1);
            
        } catch (Exception $e) {
            error_log("Error checking supervisor status: " . $e->getMessage());
            return false;
        }
    }
}

// Helpers specific to sidebar (guarded)
if (!function_exists('url_to')) {
    function url_to($path) {
        // Build URL relative to project base
        return BASE_URL . ltrim($path, '/');
    }
}

if (!function_exists('is_active')) {
    /**
     * Active class helper. Accepts one or many patterns.
     * Pattern supports:
     *  - Exact: 'dashboard'
     *  - Prefix: 'brands*' => matches '/brands', '/brands/create', etc.
     */
    function is_active($patterns, $currentPath) {
        if (!is_array($patterns)) $patterns = array($patterns);

        // Normalize current path: remove leading slash
        $cp = ltrim($currentPath, '/');

        foreach ($patterns as $p) {
            $p = ltrim($p, '/');
            if (substr($p, -1) === '*') {
                $prefix = rtrim($p, '*');
                if (strpos($cp, $prefix) === 0) return 'mm-active';
            } else {
                if ($cp === $p) return 'mm-active';
            }
        }
        return '';
    }
}
?>
<style>
    .sidebar_logo_ad {
        height: 100px;
        margin-right: 10px;
        width: 100px !important;
        border-radius: 50%;
        margin-top: -14px;
        margin-bottom: -20px;
        margin-left: 46px;
        box-shadow: rgba(50, 50, 93, 0.25) 0px 50px 100px -20px,
                    rgba(0, 0, 0, 0.3) 0px 30px 60px -30px;
        border: 2px solid #64C5B1;
    }
    @media (max-width: 768px) {
        .sidebar_logo_ad {
            height: 105px !important;
            width: 105px !important;
            border-radius: 50% !important;
            box-shadow: rgba(50, 50, 93, 0.25) 0px 50px 100px -20px,
                        rgba(0, 0, 0, 0.3) 0px 30px 60px -30px;
            margin-left: 6px !important;
            margin-bottom: -30px !important;
            margin-top: -40px !important;
        }
        .sidebar_logo_ad_span{
            margin-top: 37px !important;
            font-size: 13px !important;
            margin-bottom: -4px;
        }
    }
    .icon_menu i{ font-size:18px; color:#334155; }

    /* main menu link */
    #sidebar_menu > li > a{
        display:flex;
        align-items:center;
        gap:10px;
    }

    #sidebar_menu > li.mm-active > a span,
    #sidebar_menu > li > a:hover span{ font-weight:700; }

    /* submenu link */
    #sidebar_menu ul li a{
        padding-left:42px;
        display:flex;
        align-items:center;
        gap:8px;
        font-size: 13px;
    }
    .submenu-icon{
        width:16px;
        text-align:center;
        font-size: 14px;
        color:#64748b;
    
    }
    
</style>

<nav class="sidebar vertical-scroll ps-container ps-theme-default ps-active-y">
    <div class="logo d-flex justify-content-between" style="padding-bottom: 0px!important;">
        <a style="display:flex; justify-content:center; flex-direction:column;"
           href="<?php echo htmlspecialchars(url_to('/dashboard.php')); ?>">
            <img class="sidebar_logo_ad"
                 src="<?php echo htmlspecialchars(asset('/../assets/logo.png')); ?>"
                 alt="Logo">
            <span class="sidebar_logo_ad_span" style="
    font-size: 18px;
    font-weight: 700;
    display: flex;
    justify-content: center;
    padding-top: 10px;
    color: #22009d;
    text-shadow: 0px 1px 0px #0ebaff;
    text-transform: uppercase;
    margin-top: 22px;">
    
</span>

        </a>
        <div class="sidebar_close_icon d-lg-none">
            <i class="ti-close"></i>
        </div>
    </div>

    <ul id="sidebar_menu">
        <!-- Dashboard -->
        <li class="">
            <a href="<?php echo htmlspecialchars(url_to('/dashboard.php')); ?>" aria-expanded="false">
                <div class="icon_menu"><i class="fa-solid fa-gauge-high"></i></div>
                <span>Dashboard</span>
            </a>
        </li>

        <!-- ======================= LEAVE ======================= -->
        <!-- <li class="<?php echo is_active(array(
            'pages/leave/leave_apply.php',
            'pages/leave/leave_manage.php',
            'pages/leave/leave_approval_supervisor.php',
            'pages/leave_types/leave_types.php'
        ), $currentPath); ?>">
            <a class="has-arrow" href="#" aria-expanded="false">
                <div class="icon_menu"><i class="fa-solid fa-calendar-days"></i></div>
                <span>Leave</span>
            </a>
            <ul>
                Leave Apply - Always visible for all employees -->
                <!-- <li>
                    <a href="<?php echo htmlspecialchars(url_to('/pages/leave/leave_apply.php')); ?>">
                        <span class="submenu-icon"><i class="fa-solid fa-paper-plane"></i></span>
                        <span style="margin-left: -22px;">Leave Apply</span>
                    </a>
                </li> -->

                <!-- Leave Manage - For employees to view their leaves -->
                <!-- <li>
                    <a href="<?php echo htmlspecialchars(url_to('/pages/leave/leave_manage.php')); ?>">
                        <span class="submenu-icon"><i class="fa-solid fa-list-check"></i></span>
                        <span style="margin-left: -22px;">My Leaves</span>
                    </a>
                </li> -->

                <!-- Leave Approval Supervisor - ONLY FOR SUPERVISORS -->
                <?php 
                // Check if current user is a supervisor
                $isSupervisor = false;
                if (function_exists('is_user_supervisor')) {
                    $isSupervisor = is_user_supervisor();
                }
                // Debug: You can uncomment this line to see the result
                // echo "<!-- DEBUG: User ID from session: " . ($_SESSION['auth_user']['EmployeeID'] ?? 'NOT SET') . " -->";
                // echo "<!-- DEBUG: Is supervisor: " . ($isSupervisor ? 'YES' : 'NO') . " -->";
                ?>
                
                <!-- <?php if ($isSupervisor): ?>
                <li>
                    <a href="<?php echo htmlspecialchars(url_to('/pages/leave/leave_approval_supervisor.php')); ?>">
                        <span class="submenu-icon"><i class="fa-solid fa-user-check"></i></span>
                        <span style="margin-left: -22px;">Approve Leaves</span>
                    </a>
                </li>
                <?php endif; ?> -->

                <!-- Leave Types - Only for HR/admin users -->
                <!-- <?php if (can('leave_types.manage')): ?>
                <li>
                    <a href="<?php echo htmlspecialchars(url_to('/pages/leave_types/leave_types.php')); ?>">
                        <span class="submenu-icon"><i class="fa-solid fa-layer-group"></i></span>
                        <span style="margin-left: -22px;">Leave Types</span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </li> --> 

        <!-- ======================= HR MANAGEMENT ======================= -->
        <?php if (
            can('employees.manage') ||
            can('employees.history') ||
            can('departments.manage') ||
            can('designations.manage') ||
            can('locations.manage')
        ): ?>
        <li class="<?php echo is_active(array(
            'pages/employee/employees.php',
            'pages/employee/employees_history.php',
            'pages/employee/employee_current_list.php',
            'pages/department/departments.php',
            'pages/designation/designations.php',
            'pages/location/locations.php',
            'pages/location/admin_assign_supervisors.php'
        ), $currentPath); ?>">
            <a class="has-arrow" href="#" aria-expanded="false">
                <div class="icon_menu"><i class="fa-solid fa-users"></i></div>
                <span>HR Management</span>
            </a>
            <ul>
                <!-- Employee Master (create / edit) -->
                <?php if (can('employees.manage')): ?>
                <li>
                    <a href="<?php echo htmlspecialchars(url_to('/pages/employee/employees.php')); ?>">
                        <span class="submenu-icon"><i class="fa-solid fa-id-badge"></i></span>
                        <span style="margin-left: -22px;">Employee Master</span>
                    </a>
                </li>

                <li>
                    <!-- <a href="<?php echo htmlspecialchars(url_to('/pages/employee/admin_assign_supervisors.php')); ?>">
                        <span class="submenu-icon"><i class="fa-solid fa-id-badge"></i></span>
                        <span style="margin-left: -22px;">Assign Supervisor</span>
                    </a> -->
                </li>
                <?php endif; ?>

                <!-- Department -->
                <?php if (can('departments.manage')): ?>
                <li>
                    <a href="<?php echo htmlspecialchars(url_to('/pages/department/departments.php')); ?>">
                        <span class="submenu-icon"><i class="fa-solid fa-building"></i></span>
                        <span style="margin-left: -22px;">Department</span>
                    </a>
                </li>
                <?php endif; ?>

                <!-- Designation -->
                <?php if (can('designations.manage')): ?>
                <li>
                    <a href="<?php echo htmlspecialchars(url_to('/pages/designation/designations.php')); ?>">
                        <span class="submenu-icon"><i class="fa-solid fa-id-card-clip"></i></span>
                        <span style="margin-left: -22px;">Designation</span>
                    </a>
                </li>
                <?php endif; ?>

                <!-- Location -->
                <?php if (can('locations.manage')): ?>
                <li>
                    <a href="<?php echo htmlspecialchars(url_to('/pages/location/locations.php')); ?>">
                        <span class="submenu-icon"><i class="fa-solid fa-location-dot"></i></span>
                        <span style="margin-left: -22px;">Location</span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </li>
        <?php endif; ?>

        <!-- ======================= ASSET GROUP ======================= -->
        

        <!-- ======================= REPORT ======================= -->
        <li class="<?php echo is_active('pages/reports/reports.php', $currentPath); ?>">
            <a href="<?php echo htmlspecialchars(url_to('/pages/reports/reports.php')); ?>" aria-expanded="false">
                <div class="icon_menu"><i class="fa-solid fa-chart-column"></i></div>
                <span>Reports</span>
            </a>
        </li>

        <!-- ======================= ACCESS CONTROL ======================= -->
        <?php if (can('access.manage')): ?>
        <li class="<?php echo is_active(array(
            'pages/account/users.php',
            'pages/role/permissions.php',
            'pages/role/roles.php',
            'pages/role/assign.php',
            'pages/role/user_access.php'
        ), $currentPath); ?>">
            <a class="has-arrow" href="#" aria-expanded="false">
                <div class="icon_menu"><i class="fa-solid fa-shield-halved"></i></div>
                <span>Access Control</span>
            </a>
            <ul>
                <li>
                    <a href="<?php echo htmlspecialchars(url_to('/pages/account/users.php')); ?>">
                        <span class="submenu-icon"><i class="fa-solid fa-user-plus"></i></span>
                        <span style="margin-left: -22px;">Add User</span>
                    </a>
                </li>
                <li>
                    <a href="<?php echo htmlspecialchars(url_to('/pages/role/permissions.php')); ?>">
                        <span class="submenu-icon"><i class="fa-solid fa-key"></i></span>
                        <span style="margin-left: -22px;">Manage Permissions</span>
                    </a>
                </li>
                <li>
                    <a href="<?php echo htmlspecialchars(url_to('/pages/role/roles.php')); ?>">
                        <span class="submenu-icon"><i class="fa-solid fa-user-tag"></i></span>
                        <span style="margin-left: -22px;">Manage Roles</span>
                    </a>
                </li>
                <li>
                    <a href="<?php echo htmlspecialchars(url_to('/pages/role/assign.php')); ?>">
                        <span class="submenu-icon"><i class="fa-solid fa-user-check"></i></span>
                        <span style="margin-left: -22px;">Assign Permissions</span>
                    </a>
                </li>
                <li>
                    <a href="<?php echo htmlspecialchars(url_to('/pages/role/user_access.php')); ?>">
                        <span class="submenu-icon"><i class="fa-solid fa-users-gear"></i></span>
                        <span style="margin-left: -22px;">User Access</span>
                    </a>
                </li>
            </ul>
        </li>
        <?php endif; ?>

    </ul>
</nav>