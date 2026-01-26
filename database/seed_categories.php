<?php
/**
 * Service Categories Seeder
 * Seeds all service provider categories from client categories
 * 
 * Usage: php seed_categories.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';

// Categories from client/app/utils/categories.ts
$categories = [
    [
        'name' => 'Automotive Services',
        'slug' => 'automotive-services',
        'subcategories' => [
            'Auto Body Repair Shops', 'Auto Detailing Specialists', 'Auto Glass Repair Services',
            'Auto Parts Retailers', 'Auto Repair Shops', 'Auto Towing Services',
            'Auto Transmission Specialists', 'Car Wash Services', 'Mobile Mechanics',
            'Oil Change Stations', 'Tire Dealers', 'Vehicle Inspection Stations',
        ],
    ],
    [
        'name' => 'Building & Construction',
        'slug' => 'building-and-construction',
        'subcategories' => [
            'Architectural Firms', 'Bricklayers', 'Building Inspectors', 'Carpenters',
            'Concrete Contractors', 'Demolition Services', 'Electricians', 'Excavation Contractors',
            'Flooring Installers', 'General Contractors', 'Glaziers', 'HVAC Technicians',
            'Insulation Contractors', 'Land Surveyors', 'Landscapers', 'Masons',
            'Painters', 'Plasterers', 'Plumbers', 'Roofers', 'Structural Engineers',
            'Tile Setters', 'Welders',
        ],
    ],
    [
        'name' => 'Transport, Cargo & Logistics Services',
        'slug' => 'transport-cargo-and-logistics-services',
        'subcategories' => [
            'Air Freight Carriers', 'Bicycle Couriers', 'Customs Brokers', 'Delivery Services',
            'Freight Forwarders', 'Logistics Consultants', 'Maritime Shipping Companies',
            'Moving Companies', 'Package Tracking Services', 'Rail Freight Operators',
            'Supply Chain Managers', 'Third-Party Logistics (3PL) Providers', 'Trucking Companies',
            'Warehousing Services',
        ],
    ],
    [
        'name' => 'Care Services',
        'slug' => 'care-services',
        'subcategories' => [
            'Adult Day Care Centers', 'Childcare Providers', 'Elderly Companions',
            'Home Health Aides', 'Hospice Care Providers', 'Nanny Services',
            'Personal Care Assistants', 'Respite Care Providers', 'Special Needs Caregivers',
        ],
    ],
    [
        'name' => 'Education',
        'slug' => 'education',
        'subcategories' => [
            'Adult Education Centers', 'Art Schools', 'Business Schools', 'Colleges',
            'Dance Schools', 'Driving Schools', 'Language Schools', 'Music Schools',
            'Online Course Providers', 'Primary Schools', 'Secondary Schools',
            'Special Education Services', 'Technical Institutes', 'Tutoring Services',
            'Universities', 'Vocational Training Centers',
        ],
    ],
    [
        'name' => 'Cleaning Services',
        'slug' => 'cleaning-services',
        'subcategories' => [
            'Carpet Cleaners', 'Chimney Sweeps', 'Commercial Cleaning Services', 'Dry Cleaners',
            'Gutter Cleaning Services', 'House Cleaning Services', 'Janitorial Services',
            'Laundry Services', 'Maid Services', 'Pool Cleaning Services',
            'Pressure Washing Services', 'Window Cleaning Services',
        ],
    ],
    [
        'name' => 'Computer & IT Services',
        'slug' => 'computer-and-it-services',
        'subcategories' => [
            'Cloud Service Providers', 'Computer Repair Technicians', 'Cybersecurity Consultants',
            'Data Recovery Specialists', 'IT Support Services', 'Managed IT Services',
            'Network Administrators', 'Software Developers', 'System Integrators',
            'Technical Support Services', 'Web Designers', 'Web Hosting Providers',
        ],
    ],
    [
        'name' => 'Entertainment Services',
        'slug' => 'entertainment-services',
        'subcategories' => [
            'Bands', 'Comedians', 'DJs', 'Event Planners', 'Magicians', 'Musicians',
            'Party Entertainers', 'Photographers', 'Public Speakers', 'Theater Companies',
            'Videographers',
        ],
    ],
    [
        'name' => 'Fitness & Personal Training Services',
        'slug' => 'fitness-and-personal-training-services',
        'subcategories' => [
            'Fitness Instructors', 'Gym Owners', 'Martial Arts Instructors', 'Nutritionists',
            'Personal Trainers', 'Pilates Instructors', 'Wellness Coaches', 'Yoga Instructors',
        ],
    ],
    [
        'name' => 'Health & Beauty Services',
        'slug' => 'health-and-beauty-services',
        'subcategories' => [
            'Acupuncturists', 'Barbers', 'Beauticians', 'Chiropractors', 'Cosmetologists',
            'Dentists', 'Dermatologists', 'Estheticians', 'Hair Stylists', 'Massage Therapists',
            'Nail Technicians', 'Optometrists', 'Personal Care Assistants', 'Physicians',
            'Spa Owners',
        ],
    ],
    [
        'name' => 'Landscaping and Gardening Services',
        'slug' => 'landscaping-and-gardening-services',
        'subcategories' => [
            'Arborists', 'Garden Designers', 'Gardeners', 'Horticulturists',
            'Irrigation Specialists', 'Landscape Architects', 'Landscape Designers',
            'Lawn Care Services', 'Tree Surgeons',
        ],
    ],
    [
        'name' => 'Legal Services',
        'slug' => 'legal-services',
        'subcategories' => [
            'Arbitrators', 'Attorneys', 'Compliance Officers', 'Corporate Lawyers',
            'Family Lawyers', 'Immigration Lawyers', 'Legal Consultants', 'Legal Secretaries',
            'Mediators', 'Paralegals', 'Patent Attorneys', 'Personal Injury Lawyers',
            'Real Estate Lawyers',
        ],
    ],
    [
        'name' => 'Manufacturing Services',
        'slug' => 'manufacturing-services',
        'subcategories' => [
            'Assembly Line Workers', 'CNC Machinists', 'Fabricators', 'Industrial Designers',
            'Machine Operators', 'Manufacturing Engineers', 'Metalworkers', 'Production Managers',
            'Quality Control Inspectors', 'Tool and Die Makers', 'Welders',
        ],
    ],
    [
        'name' => 'Catering & Events Services',
        'slug' => 'catering-and-events-services',
        'subcategories' => [
            'Bakers', 'Banquet Managers', 'Caterers', 'Event Coordinators', 'Event Planners',
            'Florists', 'Party Rental Suppliers', 'Wedding Planners',
        ],
    ],
    [
        'name' => 'Pet Services',
        'slug' => 'pet-services',
        'subcategories' => [
            'Animal Trainers', 'Dog Walkers', 'Groomers', 'Pet Boarding Services',
            'Pet Sitters', 'Veterinarians', 'Veterinary Technicians',
        ],
    ],
    [
        'name' => 'Photography & Video Services',
        'slug' => 'photography-and-video-services',
        'subcategories' => [
            'Cinematographers', 'Drone Operators', 'Photo Editors', 'Photographers', 'Videographers',
        ],
    ],
    [
        'name' => 'Printing Services',
        'slug' => 'printing-services',
        'subcategories' => [
            'Bindery Operators', 'Graphic Designers', 'Offset Printers', 'Print Brokers',
            'Screen Printers', 'Sign Makers',
        ],
    ],
    [
        'name' => 'Recruitment Services',
        'slug' => 'recruitment-services',
        'subcategories' => [
            'Career Coaches', 'Employment Agencies', 'Executive Search Firms', 'Headhunters',
            'HR Consultants', 'Job Placement Services', 'Recruiters', 'Staffing Agencies',
        ],
    ],
    [
        'name' => 'Rental Services',
        'slug' => 'rental-services',
        'subcategories' => [
            'Car Rental Agencies', 'Equipment Rental Services', 'Furniture Rental Stores',
            'Party Supply Rentals', 'Tool Rental Services',
        ],
    ],
    [
        'name' => 'Repair Services',
        'slug' => 'repair-services',
        'subcategories' => [
            'Appliance Repair Technicians', 'Bicycle Repair Shops', 'Computer Repair Technicians',
            'Electronics Repair Services', 'Furniture Repair Specialists', 'Jewelry Repair Services',
            'Mobile Phone Repair Technicians', 'Shoe Repair Shops', 'Watch Repair Services',
        ],
    ],
    [
        'name' => 'Tax & Financial Services',
        'slug' => 'tax-and-financial-services',
        'subcategories' => [
            'Accountants', 'Auditors', 'Bookkeepers', 'Financial Advisors', 'Payroll Services',
            'Tax Consultants', 'Tax Preparers',
        ],
    ],
    [
        'name' => 'Travel Agents & Tours',
        'slug' => 'travel-agents-and-tours',
        'subcategories' => [
            'Adventure Tour Operators', 'Cruise Planners', 'Destination Management Companies',
            'Eco-Tourism Guides', 'Travel Agents', 'Travel Consultants', 'Travel Guides',
            'Vacation Planners',
        ],
    ],
    [
        'name' => 'Media Services',
        'slug' => 'media-services',
        'subcategories' => [
            'Advertising Agencies', 'Audio Engineers', 'Broadcast Technicians', 'Content Creators',
            'Copywriters', 'Digital Marketers', 'Documentary Filmmakers', 'Event Photographers',
            'Film Editors', 'Graphic Designers', 'Headshot Photographers', 'Influencer Managers',
            'Journalists', 'Karaoke Hosts', 'Lighting Technicians', 'Media Buyers',
            'Motion Graphics Artists', 'News Anchors', 'Online Community Managers',
            'Public Relations Specialists', 'Podcast Producers', 'Quality Assurance Editors',
            'Radio DJs', 'Social Media Managers', 'Sound Designers', 'Television Producers',
            'Translators', 'UX/UI Designers', 'Videographers', 'Voice-over Artists',
            'Web Developers', 'Writers', 'XR Developers', 'YouTube Content Creators',
            'Zoom Event Coordinators',
        ],
    ],
];

function generateUUID() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function slugify($text) {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9-]/', '-', $text);
    $text = preg_replace('/-+/', '-', $text);
    return trim($text, '-');
}

try {
    $db = Database::getInstance()->getConnection();
    
    $inserted = 0;
    $skipped = 0;
    $errors = 0;
    $sortOrder = 0;
    
    foreach ($categories as $category) {
        $parentId = generateUUID();
        $parentSlug = $category['slug'];
        $parentName = $category['name'];
        
        // Check if parent category exists
        $checkStmt = $db->prepare("SELECT id FROM service_categories WHERE slug = ?");
        $checkStmt->execute([$parentSlug]);
        $existing = $checkStmt->fetch();
        
        if ($existing) {
            $parentId = $existing['id'];
            echo "✓ Parent category '{$parentName}' already exists\n";
        } else {
            // Insert parent category
            $parentStmt = $db->prepare("
                INSERT INTO service_categories (id, name, slug, sort_order, is_active)
                VALUES (?, ?, ?, ?, 1)
            ");
            $parentStmt->execute([$parentId, $parentName, $parentSlug, $sortOrder++]);
            echo "✓ Created parent category: {$parentName}\n";
            $inserted++;
        }
        
        // Insert subcategories
        foreach ($category['subcategories'] as $subName) {
            $subSlug = slugify($subName);
            
            // Check if subcategory already exists (by name+parent_id - this is the unique constraint)
            $checkSubStmt = $db->prepare("
                SELECT id FROM service_categories 
                WHERE name = ? AND parent_id = ?
            ");
            $checkSubStmt->execute([$subName, $parentId]);
            $existingSub = $checkSubStmt->fetch();
            
            if ($existingSub) {
                $skipped++;
                continue; // Already exists under this parent
            }
            
            // If slug already exists (under different parent), make it unique by appending parent slug
            $slugCheckStmt = $db->prepare("SELECT id FROM service_categories WHERE slug = ?");
            $slugCheckStmt->execute([$subSlug]);
            if ($slugCheckStmt->fetch()) {
                // Slug exists under different parent, append parent slug to make it unique
                $subSlug = $parentSlug . '-' . $subSlug;
            }
            
            $subId = generateUUID();
            try {
                $subStmt = $db->prepare("
                    INSERT INTO service_categories (id, name, slug, parent_id, sort_order, is_active)
                    VALUES (?, ?, ?, ?, ?, 1)
                ");
                $subStmt->execute([$subId, $subName, $subSlug, $parentId, $sortOrder++]);
                $inserted++;
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    // Duplicate entry (shouldn't happen with our check, but handle gracefully)
                    $skipped++;
                    echo "  ⚠ Skipped duplicate: {$subName} under {$parentName}\n";
                } else {
                    $errors++;
                    echo "  ✗ Error inserting {$subName}: " . $e->getMessage() . "\n";
                }
            }
        }
    }
    
    echo "\n✓ Categories seeding completed!\n";
    echo "  Inserted: {$inserted} categories\n";
    echo "  Skipped: {$skipped} (already exist)\n";
    if ($errors > 0) {
        echo "  Errors: {$errors} (duplicates or conflicts)\n";
    }
    
} catch (Exception $e) {
    die("✗ Error: " . $e->getMessage() . "\n");
}
