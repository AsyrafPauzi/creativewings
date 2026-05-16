<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Business {

    // Shared SDG Map (Used by Form & Save classes)
    public static $sdg_map = [
        1 => 'No Poverty', 2 => 'Zero Hunger', 3 => 'Good Health and Well-Being',
        4 => 'Quality Education', 5 => 'Gender Equality', 6 => 'Clean Water and Sanitation',
        7 => 'Affordable and Clean Energy', 8 => 'Decent Work and Economic Growth', 9 => 'Industry, Innovation, and Infrastructure',
        10 => 'Reduced Inequalities', 11 => 'Sustainable Cities and Communities', 12 => 'Responsible Consumption and Production',
        13 => 'Climate Action', 14 => 'Life Below Water', 15 => 'Life on Land',
        16 => 'Peace, Justice, and Strong Institutions', 17 => 'Partnerships for the Goals'
    ];

    public function __construct() {
        $this->includes();
        
        // Initialize Sub-Modules
        new CW_Business_Form();
        new CW_Business_Save();
    }

    private function includes() {
        // Ensure the folder 'includes/business/' exists
        require_once CW_PATH . 'includes/business/class-cw-campaign-persistence.php';
        require_once CW_PATH . 'includes/business/class-cw-business-form.php';
        require_once CW_PATH . 'includes/business/class-cw-business-save.php';
        require_once CW_PATH . 'includes/business/class-cw-campaign-import.php';
    }

    public static function get_sdg_map() {
        return self::$sdg_map;
    }
}