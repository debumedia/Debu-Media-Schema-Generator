<?php
/**
 * Schema.org reference data
 *
 * Provides comprehensive schema.org type definitions and property references
 * to guide the LLM in generating rich, complete structured data.
 *
 * @package WP_AI_Schema_Generator
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Schema.org type definitions and property references
 */
class WP_AI_Schema_Reference {

    /**
     * Get all schema definitions
     *
     * @return array All schema type definitions.
     */
    public static function get_all_definitions(): array {
        return array(
            'Organization'   => self::get_organization_schema(),
            'LocalBusiness'  => self::get_local_business_schema(),
            'Service'        => self::get_service_schema(),
            'Product'        => self::get_product_schema(),
            'Person'         => self::get_person_schema(),
            'Event'          => self::get_event_schema(),
            'FAQPage'        => self::get_faq_page_schema(),
            'Article'        => self::get_article_schema(),
            'WebPage'        => self::get_webpage_schema(),
            'HowTo'          => self::get_howto_schema(),
            'ContactPoint'   => self::get_contact_point_schema(),
            'PostalAddress'  => self::get_postal_address_schema(),
            'Offer'          => self::get_offer_schema(),
            'Review'         => self::get_review_schema(),
        );
    }

    /**
     * Get relevant schema types based on type hint
     *
     * @param string $type_hint User's type hint or 'auto'.
     * @return array Relevant schema types to include in reference.
     */
    public static function get_relevant_types( string $type_hint ): array {
        // Type hint to related types mapping
        $type_families = array(
            'auto'           => array( 'WebPage', 'Organization', 'Service', 'LocalBusiness', 'FAQPage', 'Article', 'ContactPoint', 'PostalAddress' ),
            'Organization'   => array( 'Organization', 'ContactPoint', 'PostalAddress', 'Person' ),
            'LocalBusiness'  => array( 'LocalBusiness', 'ContactPoint', 'PostalAddress', 'Offer', 'Review', 'Service' ),
            'Service'        => array( 'Service', 'Offer', 'Organization', 'ContactPoint', 'Review' ),
            'Product'        => array( 'Product', 'Offer', 'Review', 'Organization' ),
            'Person'         => array( 'Person', 'ContactPoint', 'PostalAddress', 'Organization' ),
            'Event'          => array( 'Event', 'Offer', 'Organization', 'PostalAddress', 'Person' ),
            'FAQPage'        => array( 'FAQPage', 'WebPage', 'Organization' ),
            'Article'        => array( 'Article', 'Organization', 'Person', 'WebPage' ),
            'WebPage'        => array( 'WebPage', 'Organization', 'ContactPoint' ),
            'HowTo'          => array( 'HowTo', 'WebPage', 'Organization' ),
        );

        return $type_families[ $type_hint ] ?? array( $type_hint, 'Organization', 'ContactPoint' );
    }

    /**
     * Get schema definitions for specific types
     *
     * @param array $types List of schema types to include.
     * @return array Filtered schema definitions.
     */
    public static function get_definitions_for_types( array $types ): array {
        $all      = self::get_all_definitions();
        $filtered = array();

        foreach ( $types as $type ) {
            if ( isset( $all[ $type ] ) ) {
                $filtered[ $type ] = $all[ $type ];
            }
        }

        return $filtered;
    }

    /**
     * Format schema reference for prompt inclusion
     *
     * @param array $definitions Schema definitions to format.
     * @return string Formatted reference text for the LLM.
     */
    public static function format_for_prompt( array $definitions ): string {
        $output  = "=== SCHEMA.ORG PROPERTY REFERENCE ===\n";
        $output .= "Use these properties to create COMPREHENSIVE schemas. Include all applicable properties.\n\n";

        foreach ( $definitions as $type => $def ) {
            $output .= "--- {$type} ---\n";
            $output .= "{$def['description']}\n";
            $output .= "Properties:\n";

            foreach ( $def['properties'] as $prop => $prop_def ) {
                $marker = ! empty( $prop_def['recommended'] ) ? '[REC] ' : '      ';
                $output .= "{$marker}{$prop}: {$prop_def['description']}";
                if ( ! empty( $prop_def['type'] ) ) {
                    $output .= " (Type: {$prop_def['type']})";
                }
                $output .= "\n";
            }

            // Include nested type info if present
            if ( ! empty( $def['nested'] ) ) {
                foreach ( $def['nested'] as $nested_type => $nested_props ) {
                    $output .= "  Nested {$nested_type}:\n";
                    foreach ( $nested_props as $prop => $prop_def ) {
                        $output .= "    - {$prop}: {$prop_def['description']}\n";
                    }
                }
            }

            $output .= "\n";
        }

        $output .= "[REC] = Recommended property - include when data is available\n";

        return $output;
    }

    /**
     * Organization schema definition
     */
    private static function get_organization_schema(): array {
        return array(
            'description' => 'An organization such as a company, NGO, club, or institution.',
            'properties'  => array(
                'name'              => array( 'description' => 'Organization name', 'recommended' => true ),
                'url'               => array( 'description' => 'Website URL', 'recommended' => true ),
                'logo'              => array( 'description' => 'Logo image URL', 'recommended' => true, 'type' => 'URL' ),
                'description'       => array( 'description' => 'Brief description of the organization', 'recommended' => true ),
                'email'             => array( 'description' => 'Contact email address' ),
                'telephone'         => array( 'description' => 'Phone number' ),
                'address'           => array( 'description' => 'Physical address', 'type' => 'PostalAddress' ),
                'sameAs'            => array( 'description' => 'Social media profile URLs', 'type' => 'Array of URLs' ),
                'contactPoint'      => array( 'description' => 'Contact information', 'type' => 'ContactPoint' ),
                'foundingDate'      => array( 'description' => 'Date organization was founded', 'type' => 'Date' ),
                'founder'           => array( 'description' => 'Founder(s)', 'type' => 'Person' ),
                'numberOfEmployees' => array( 'description' => 'Number of employees' ),
                'areaServed'        => array( 'description' => 'Geographic area served' ),
                'slogan'            => array( 'description' => 'Organization slogan or tagline' ),
            ),
        );
    }

    /**
     * LocalBusiness schema definition
     */
    private static function get_local_business_schema(): array {
        return array(
            'description' => 'A local business with a physical location. Use specific subtypes like Restaurant, Store, etc.',
            'properties'  => array(
                'name'            => array( 'description' => 'Business name', 'recommended' => true ),
                'url'             => array( 'description' => 'Website URL', 'recommended' => true ),
                'image'           => array( 'description' => 'Business photos', 'recommended' => true, 'type' => 'URL' ),
                'address'         => array( 'description' => 'Physical address', 'recommended' => true, 'type' => 'PostalAddress' ),
                'telephone'       => array( 'description' => 'Phone number', 'recommended' => true ),
                'email'           => array( 'description' => 'Email address' ),
                'description'     => array( 'description' => 'Business description' ),
                'openingHours'    => array( 'description' => 'Opening hours (e.g., "Mo-Fr 09:00-17:00")' ),
                'priceRange'      => array( 'description' => 'Price range (e.g., "$$$" or "$10-50")' ),
                'geo'             => array( 'description' => 'Geographic coordinates', 'type' => 'GeoCoordinates' ),
                'areaServed'      => array( 'description' => 'Service area' ),
                'paymentAccepted' => array( 'description' => 'Payment methods accepted' ),
                'aggregateRating' => array( 'description' => 'Average rating', 'type' => 'AggregateRating' ),
                'review'          => array( 'description' => 'Customer reviews', 'type' => 'Review' ),
                'hasOfferCatalog' => array( 'description' => 'Services/products offered', 'type' => 'OfferCatalog' ),
            ),
        );
    }

    /**
     * Service schema definition
     */
    private static function get_service_schema(): array {
        return array(
            'description' => 'A service provided by an organization or person.',
            'properties'  => array(
                'name'           => array( 'description' => 'Service name', 'recommended' => true ),
                'description'    => array( 'description' => 'Service description', 'recommended' => true ),
                'provider'       => array( 'description' => 'Who provides this service', 'recommended' => true, 'type' => 'Organization or Person' ),
                'serviceType'    => array( 'description' => 'Type/category of service' ),
                'areaServed'     => array( 'description' => 'Geographic area where available' ),
                'audience'       => array( 'description' => 'Target audience' ),
                'offers'         => array( 'description' => 'Pricing information', 'type' => 'Offer' ),
                'category'       => array( 'description' => 'Service category' ),
                'termsOfService' => array( 'description' => 'Terms of service URL' ),
                'aggregateRating' => array( 'description' => 'Average rating', 'type' => 'AggregateRating' ),
                'review'         => array( 'description' => 'Service reviews', 'type' => 'Review' ),
            ),
        );
    }

    /**
     * Product schema definition
     */
    private static function get_product_schema(): array {
        return array(
            'description' => 'A product offered for sale.',
            'properties'  => array(
                'name'           => array( 'description' => 'Product name', 'recommended' => true ),
                'description'    => array( 'description' => 'Product description', 'recommended' => true ),
                'image'          => array( 'description' => 'Product images', 'recommended' => true, 'type' => 'URL' ),
                'offers'         => array( 'description' => 'Pricing and availability', 'recommended' => true, 'type' => 'Offer' ),
                'brand'          => array( 'description' => 'Product brand', 'type' => 'Brand' ),
                'sku'            => array( 'description' => 'Stock keeping unit' ),
                'category'       => array( 'description' => 'Product category' ),
                'color'          => array( 'description' => 'Product color' ),
                'material'       => array( 'description' => 'Product material' ),
                'aggregateRating' => array( 'description' => 'Average rating', 'type' => 'AggregateRating' ),
                'review'         => array( 'description' => 'Product reviews', 'type' => 'Review' ),
            ),
        );
    }

    /**
     * Person schema definition
     */
    private static function get_person_schema(): array {
        return array(
            'description' => 'A person - team member, founder, author, etc.',
            'properties'  => array(
                'name'        => array( 'description' => 'Full name', 'recommended' => true ),
                'jobTitle'    => array( 'description' => 'Job title or role', 'recommended' => true ),
                'url'         => array( 'description' => 'Personal website or profile URL' ),
                'image'       => array( 'description' => 'Photo', 'type' => 'URL' ),
                'description' => array( 'description' => 'Bio or description' ),
                'worksFor'    => array( 'description' => 'Employer organization', 'type' => 'Organization' ),
                'email'       => array( 'description' => 'Email address' ),
                'telephone'   => array( 'description' => 'Phone number' ),
                'sameAs'      => array( 'description' => 'Social media profiles', 'type' => 'Array of URLs' ),
                'alumniOf'    => array( 'description' => 'Educational background', 'type' => 'Organization' ),
            ),
        );
    }

    /**
     * Event schema definition
     */
    private static function get_event_schema(): array {
        return array(
            'description' => 'An event happening at a specific time and location.',
            'properties'  => array(
                'name'        => array( 'description' => 'Event name', 'recommended' => true ),
                'startDate'   => array( 'description' => 'Start date/time', 'recommended' => true, 'type' => 'DateTime' ),
                'location'    => array( 'description' => 'Event location', 'recommended' => true, 'type' => 'Place or VirtualLocation' ),
                'description' => array( 'description' => 'Event description' ),
                'endDate'     => array( 'description' => 'End date/time', 'type' => 'DateTime' ),
                'image'       => array( 'description' => 'Event image', 'type' => 'URL' ),
                'organizer'   => array( 'description' => 'Event organizer', 'type' => 'Organization or Person' ),
                'performer'   => array( 'description' => 'Performers', 'type' => 'Person or Organization' ),
                'offers'      => array( 'description' => 'Ticket information', 'type' => 'Offer' ),
                'eventStatus' => array( 'description' => 'EventScheduled, EventCancelled, etc.' ),
                'eventAttendanceMode' => array( 'description' => 'OfflineEventAttendanceMode, OnlineEventAttendanceMode, or MixedEventAttendanceMode' ),
            ),
        );
    }

    /**
     * FAQPage schema definition
     */
    private static function get_faq_page_schema(): array {
        return array(
            'description' => 'A page with FAQ content. Use ONLY when page has clear Q&A pairs.',
            'properties'  => array(
                'mainEntity' => array( 'description' => 'Array of Question objects', 'recommended' => true, 'type' => 'Array of Question' ),
            ),
            'nested'      => array(
                'Question' => array(
                    'name'           => array( 'description' => 'The question text', 'recommended' => true ),
                    'acceptedAnswer' => array( 'description' => 'Answer object', 'recommended' => true, 'type' => 'Answer' ),
                ),
                'Answer' => array(
                    'text' => array( 'description' => 'The answer text', 'recommended' => true ),
                ),
            ),
        );
    }

    /**
     * Article schema definition
     */
    private static function get_article_schema(): array {
        return array(
            'description' => 'An article, blog post, or news article.',
            'properties'  => array(
                'headline'       => array( 'description' => 'Article headline (max 110 chars)', 'recommended' => true ),
                'datePublished'  => array( 'description' => 'Publication date', 'recommended' => true, 'type' => 'DateTime' ),
                'dateModified'   => array( 'description' => 'Last modified date', 'type' => 'DateTime' ),
                'author'         => array( 'description' => 'Article author', 'recommended' => true, 'type' => 'Person or Organization' ),
                'publisher'      => array( 'description' => 'Publisher', 'recommended' => true, 'type' => 'Organization' ),
                'image'          => array( 'description' => 'Article image', 'recommended' => true, 'type' => 'URL' ),
                'description'    => array( 'description' => 'Article summary' ),
                'articleBody'    => array( 'description' => 'Full article text' ),
                'wordCount'      => array( 'description' => 'Word count' ),
                'keywords'       => array( 'description' => 'Article keywords' ),
                'articleSection' => array( 'description' => 'Section/category' ),
            ),
        );
    }

    /**
     * WebPage schema definition
     */
    private static function get_webpage_schema(): array {
        return array(
            'description' => 'A web page. Often used in @graph with other types.',
            'properties'  => array(
                'name'          => array( 'description' => 'Page title', 'recommended' => true ),
                'url'           => array( 'description' => 'Page URL', 'recommended' => true ),
                'description'   => array( 'description' => 'Page description' ),
                'isPartOf'      => array( 'description' => 'Parent website', 'type' => 'WebSite' ),
                'datePublished' => array( 'description' => 'Publication date', 'type' => 'Date' ),
                'dateModified'  => array( 'description' => 'Last modified date', 'type' => 'Date' ),
                'author'        => array( 'description' => 'Page author', 'type' => 'Person or Organization' ),
                'breadcrumb'    => array( 'description' => 'Breadcrumb navigation', 'type' => 'BreadcrumbList' ),
                'mainEntity'    => array( 'description' => 'Main subject of the page', 'type' => 'Thing' ),
            ),
        );
    }

    /**
     * HowTo schema definition
     */
    private static function get_howto_schema(): array {
        return array(
            'description' => 'Instructions for accomplishing a task.',
            'properties'  => array(
                'name'          => array( 'description' => 'Title of the how-to', 'recommended' => true ),
                'step'          => array( 'description' => 'Steps to complete', 'recommended' => true, 'type' => 'Array of HowToStep' ),
                'description'   => array( 'description' => 'Description of the task' ),
                'image'         => array( 'description' => 'Image', 'type' => 'URL' ),
                'totalTime'     => array( 'description' => 'Total time required', 'type' => 'Duration' ),
                'estimatedCost' => array( 'description' => 'Estimated cost' ),
                'supply'        => array( 'description' => 'Supplies needed', 'type' => 'HowToSupply' ),
                'tool'          => array( 'description' => 'Tools needed', 'type' => 'HowToTool' ),
            ),
            'nested'      => array(
                'HowToStep' => array(
                    'name'  => array( 'description' => 'Step name/title' ),
                    'text'  => array( 'description' => 'Step instructions', 'recommended' => true ),
                    'image' => array( 'description' => 'Step image', 'type' => 'URL' ),
                ),
            ),
        );
    }

    /**
     * ContactPoint schema definition
     */
    private static function get_contact_point_schema(): array {
        return array(
            'description' => 'Contact information for an organization or person.',
            'properties'  => array(
                'contactType'       => array( 'description' => 'Type: customer service, sales, technical support, etc.', 'recommended' => true ),
                'telephone'         => array( 'description' => 'Phone number' ),
                'email'             => array( 'description' => 'Email address' ),
                'areaServed'        => array( 'description' => 'Geographic area served' ),
                'availableLanguage' => array( 'description' => 'Languages available' ),
                'hoursAvailable'    => array( 'description' => 'Hours of availability' ),
            ),
        );
    }

    /**
     * PostalAddress schema definition
     */
    private static function get_postal_address_schema(): array {
        return array(
            'description' => 'A physical mailing address.',
            'properties'  => array(
                'streetAddress'   => array( 'description' => 'Street address', 'recommended' => true ),
                'addressLocality' => array( 'description' => 'City', 'recommended' => true ),
                'addressRegion'   => array( 'description' => 'State/Province' ),
                'postalCode'      => array( 'description' => 'ZIP/Postal code' ),
                'addressCountry'  => array( 'description' => 'Country' ),
            ),
        );
    }

    /**
     * Offer schema definition
     */
    private static function get_offer_schema(): array {
        return array(
            'description' => 'An offer for a product or service.',
            'properties'  => array(
                'price'         => array( 'description' => 'Price amount', 'recommended' => true ),
                'priceCurrency' => array( 'description' => 'Currency code (USD, EUR, etc.)', 'recommended' => true ),
                'availability'  => array( 'description' => 'InStock, OutOfStock, PreOrder, etc.' ),
                'url'           => array( 'description' => 'URL to purchase' ),
                'validFrom'     => array( 'description' => 'Offer start date', 'type' => 'DateTime' ),
                'validThrough'  => array( 'description' => 'Offer end date', 'type' => 'DateTime' ),
                'seller'        => array( 'description' => 'Seller', 'type' => 'Organization' ),
            ),
        );
    }

    /**
     * Review schema definition
     */
    private static function get_review_schema(): array {
        return array(
            'description' => 'A review of an item (product, service, business).',
            'properties'  => array(
                'reviewRating'  => array( 'description' => 'Rating given', 'recommended' => true, 'type' => 'Rating' ),
                'author'        => array( 'description' => 'Review author', 'recommended' => true, 'type' => 'Person' ),
                'reviewBody'    => array( 'description' => 'Review text' ),
                'datePublished' => array( 'description' => 'Review date', 'type' => 'Date' ),
            ),
            'nested'      => array(
                'Rating' => array(
                    'ratingValue' => array( 'description' => 'Rating value (e.g., 4.5)', 'recommended' => true ),
                    'bestRating'  => array( 'description' => 'Maximum rating (e.g., 5)' ),
                    'worstRating' => array( 'description' => 'Minimum rating (e.g., 1)' ),
                ),
            ),
        );
    }
}
