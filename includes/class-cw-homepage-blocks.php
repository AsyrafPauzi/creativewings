<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Homepage storytelling blocks:
 * - [cw_featured_campaign]
 * - [cw_how_it_works]
 * - [cw_partners_marquee]
 */
class CW_Homepage_Blocks {

    public function __construct() {
        add_shortcode( 'cw_featured_campaign', [ $this, 'render_featured_campaign' ] );
        add_shortcode( 'cw_how_it_works', [ $this, 'render_how_it_works' ] );
        add_shortcode( 'cw_partners_marquee', [ $this, 'render_partners_marquee' ] );
    }

    /**
     * Featured / closing-soon campaign strip.
     *
     * Attrs: id="" (optional product ID), cta="Join now"
     */
    public function render_featured_campaign( $atts = [] ) {
        $atts = shortcode_atts(
            [
                'id'      => '',
                'cta'     => __( 'Join this campaign', 'creativewings-core' ),
                'heading' => __( 'Featured Campaign', 'creativewings-core' ),
            ],
            $atts,
            'cw_featured_campaign'
        );

        $pid = absint( $atts['id'] );
        if ( ! $pid ) {
            $pid = $this->resolve_featured_campaign_id();
        }
        if ( ! $pid || get_post_status( $pid ) !== 'publish' ) {
            return '';
        }

        $title    = get_the_title( $pid );
        $url      = get_permalink( $pid );
        $thumb    = get_the_post_thumbnail_url( $pid, 'large' );
        $deadline = (string) get_post_meta( $pid, 'submission_deadline', true );
        $org_id   = (int) get_post_meta( $pid, 'organizer_id', true );
        $org_name = $org_id ? ( get_user_meta( $org_id, 'business_name', true ) ?: '' ) : '';
        $excerpt  = wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $pid ) ), 14, '…' );

        $days_left = null;
        $closes    = '';
        if ( $deadline !== '' ) {
            $ts = CW_Campaign_Dates::timestamp( $deadline, true );
            if ( $ts ) {
                $days_left = $ts < time() ? -1 : (int) CW_Campaign_Dates::days_until( $deadline );
                $closes    = sprintf(
                    /* translators: %s: date */
                    __( 'Closes %s', 'creativewings-core' ),
                    CW_Campaign_Dates::format( $deadline )
                );
            }
        }

        $urgency = '';
        if ( $days_left !== null ) {
            if ( $days_left < 0 ) {
                return '';
            }
            if ( $days_left === 0 ) {
                $urgency = __( 'Closes today', 'creativewings-core' );
            } elseif ( $days_left === 1 ) {
                $urgency = __( 'Closes tomorrow', 'creativewings-core' );
            } elseif ( $days_left <= 30 ) {
                $urgency = sprintf(
                    /* translators: %d: days */
                    _n( '%d day left', '%d days left', $days_left, 'creativewings-core' ),
                    $days_left
                );
            } else {
                $urgency = __( 'Open now', 'creativewings-core' );
            }
        }

        $type_lbl = $this->campaign_type_label( $pid );

        ob_start();
        ?>
        <section class="cw-home-featured-wrap" data-cw-home-reveal aria-label="<?php echo esc_attr( $atts['heading'] ); ?>">
            <?php if ( trim( (string) $atts['heading'] ) !== '' ) : ?>
                <h2 class="cw-home-section-heading"><?php echo esc_html( $atts['heading'] ); ?></h2>
            <?php endif; ?>
            <div class="cw-home-featured">
                <div class="cw-home-featured__media">
                    <?php if ( $thumb ) : ?>
                        <img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) ); ?>" loading="lazy" decoding="async">
                    <?php endif; ?>
                </div>
                <div class="cw-home-featured__body">
                    <div class="cw-home-featured__meta">
                        <?php if ( $urgency !== '' ) : ?>
                            <span class="cw-home-featured__urgency"><?php echo esc_html( $urgency ); ?></span>
                        <?php endif; ?>
                        <?php if ( $type_lbl !== '' ) : ?>
                            <span class="cw-home-featured__type"><?php echo esc_html( $type_lbl ); ?></span>
                        <?php endif; ?>
                    </div>
                    <h3 class="cw-home-featured__title"><?php echo esc_html( $title ); ?></h3>
                    <?php if ( $org_name !== '' ) : ?>
                        <p class="cw-home-featured__org"><?php echo esc_html( $org_name ); ?></p>
                    <?php endif; ?>
                    <?php if ( $excerpt !== '' ) : ?>
                        <p class="cw-home-featured__excerpt"><?php echo esc_html( $excerpt ); ?></p>
                    <?php endif; ?>
                    <div class="cw-home-featured__actions">
                        <?php if ( $closes !== '' ) : ?>
                            <span class="cw-home-featured__closes"><?php echo esc_html( $closes ); ?></span>
                        <?php endif; ?>
                        <a class="cw-home-featured__cta" href="<?php echo esc_url( $url ); ?>">
                            <?php echo esc_html( $atts['cta'] ); ?>
                            <i class="fas fa-arrow-right" aria-hidden="true"></i>
                        </a>
                    </div>
                </div>
            </div>
        </section>
        <?php
        $this->enqueue_assets();
        return (string) ob_get_clean();
    }

    /**
     * How it works — Discover → Join → Create.
     */
    public function render_how_it_works( $atts = [] ) {
        $atts = shortcode_atts(
            [
                'title' => __( 'How Creative Wings works', 'creativewings-core' ),
            ],
            $atts,
            'cw_how_it_works'
        );

        $steps = [
            [
                'n'    => '01',
                'title' => __( 'Discover', 'creativewings-core' ),
                'text'  => __( 'Browse open competitions and activities happening across Malaysia.', 'creativewings-core' ),
            ],
            [
                'n'    => '02',
                'title' => __( 'Join', 'creativewings-core' ),
                'text'  => __( 'Register in minutes, submit your entry, and track your progress.', 'creativewings-core' ),
            ],
            [
                'n'    => '03',
                'title' => __( 'Create', 'creativewings-core' ),
                'text'  => __( 'Organizers launch campaigns that inspire creators and communities.', 'creativewings-core' ),
            ],
        ];

        ob_start();
        ?>
        <section class="cw-home-steps" data-cw-home-reveal aria-label="<?php echo esc_attr( $atts['title'] ); ?>">
            <h2 class="cw-home-steps__title"><?php echo esc_html( $atts['title'] ); ?></h2>
            <ol class="cw-home-steps__list">
                <?php foreach ( $steps as $i => $step ) : ?>
                    <li class="cw-home-steps__item" style="--cw-step-i: <?php echo esc_attr( (string) $i ); ?>">
                        <span class="cw-home-steps__num" aria-hidden="true"><?php echo esc_html( $step['n'] ); ?></span>
                        <h3 class="cw-home-steps__name"><?php echo esc_html( $step['title'] ); ?></h3>
                        <p class="cw-home-steps__text"><?php echo esc_html( $step['text'] ); ?></p>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
        <?php
        $this->enqueue_assets();
        return (string) ob_get_clean();
    }

    /**
     * Partner / logo marquee.
     *
     * Tiered homepage (recommended):
     *   [cw_partners_marquee heading="Our Partners" campaign_id="3361" placeholders="1"]
     *
     * Manual flat strip (About / Brand Story):
     *   [cw_partners_marquee heading="Our Partners" ids="2566,2565,3877"]
     *   [cw_partners_marquee ids="2566,2565" names="Zoom Creative,Keluang Man"]
     *   [cw_partners_marquee ids="4021,4023" links="https://yiboncreative.com/,https://sharksavers.org.my/"]
     *
     * Attrs:
     * - heading: section H2 (default "Our Partners"; empty to hide)
     * - title: optional eyebrow under the heading
     * - campaign_id: product ID for tiered bands (default 3361 when no ids)
     * - placeholders|demo: "1" fills empty Champion/Hero with “Your brand here” tiles
     * - ids: Media Library attachment IDs (comma-separated). When set → legacy flat marquee
     * - names: optional display names matched 1:1 with ids
     * - links: optional website URLs matched 1:1 with ids (opens in new tab)
     * - source: auto | manual | both (default: manual when ids set, else auto)
     * - speed: marquee duration in seconds (band 3 only)
     */
    public function render_partners_marquee( $atts = [] ) {
        $atts = shortcode_atts(
            [
                'heading'      => __( 'Our Partners', 'creativewings-core' ),
                'title'        => '',
                'campaign_id'  => '',
                'placeholders' => '0',
                'demo'         => '0',
                'ids'          => '',
                'names'        => '',
                'links'        => '',
                'source'       => '',
                'speed'        => '42',
            ],
            $atts,
            'cw_partners_marquee'
        );

        $ids_csv = trim( (string) $atts['ids'] );
        if ( $ids_csv === '' ) {
            return $this->render_tiered_partners_home( $atts );
        }

        $source = strtolower( trim( (string) $atts['source'] ) );
        if ( $source === '' ) {
            $source = 'manual';
        }
        if ( ! in_array( $source, [ 'auto', 'manual', 'both' ], true ) ) {
            $source = 'manual';
        }

        $names = array_values(
            array_filter(
                array_map( 'trim', explode( ',', (string) $atts['names'] ) ),
                static function ( $n ) {
                    return $n !== '';
                }
            )
        );

        // Keep empty slots so index alignment with ids stays intact.
        $links = array_map( 'trim', explode( ',', (string) $atts['links'] ) );

        $logos = $this->collect_partner_logos( $ids_csv, $source, $names, $links );
        if ( count( $logos ) < 2 ) {
            return '';
        }

        // Duplicate for seamless CSS loop.
        $track = array_merge( $logos, $logos );
        $label = trim( (string) $atts['heading'] ) !== ''
            ? (string) $atts['heading']
            : ( (string) $atts['title'] !== '' ? (string) $atts['title'] : __( 'Partners', 'creativewings-core' ) );

        ob_start();
        ?>
        <section class="cw-home-partners" data-cw-home-reveal aria-label="<?php echo esc_attr( $label ); ?>">
            <?php if ( trim( (string) $atts['heading'] ) !== '' ) : ?>
                <h2 class="cw-home-section-heading"><?php echo esc_html( $atts['heading'] ); ?></h2>
            <?php endif; ?>
            <?php if ( trim( (string) $atts['title'] ) !== '' ) : ?>
                <p class="cw-home-partners__title"><?php echo esc_html( $atts['title'] ); ?></p>
            <?php endif; ?>
            <div class="cw-home-partners__viewport">
                <div class="cw-home-partners__track" style="--cw-marquee-duration: <?php echo esc_attr( (string) max( 18, (int) $atts['speed'] ) ); ?>s">
                    <?php foreach ( $track as $logo ) :
                        $img = sprintf(
                            '<img src="%s" alt="%s" loading="lazy" decoding="async">',
                            esc_url( $logo['url'] ),
                            esc_attr( $logo['name'] )
                        );
                        $href = ! empty( $logo['link'] ) ? (string) $logo['link'] : '';
                        ?>
                        <div class="cw-home-partners__item">
                            <?php if ( $href !== '' ) : ?>
                                <a href="<?php echo esc_url( $href ); ?>"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   aria-label="<?php echo esc_attr( sprintf( __( 'Visit %s', 'creativewings-core' ), $logo['name'] ) ); ?>">
                                    <?php echo $img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_* above ?>
                                </a>
                            <?php else : ?>
                                <?php echo $img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php
        $this->enqueue_assets();
        return (string) ob_get_clean();
    }

    /**
     * Three-band homepage partners: Champion strip, Hero row, Community/Local/In-kind marquee.
     *
     * @param array<string,string> $atts Shortcode atts.
     * @return string
     */
    private function render_tiered_partners_home( array $atts ) {
        if ( ! class_exists( 'CW_Campaign_Showcase' ) ) {
            return '';
        }

        $campaign_id = absint( $atts['campaign_id'] ?? 0 );
        if ( $campaign_id <= 0 ) {
            $campaign_id = (int) $this->resolve_featured_campaign_id();
        }
        if ( $campaign_id <= 0 ) {
            $campaign_id = 3361;
        }

        $placeholders = in_array( (string) ( $atts['placeholders'] ?? '0' ), [ '1', 'yes', 'true' ], true )
            || in_array( (string) ( $atts['demo'] ?? '0' ), [ '1', 'yes', 'true' ], true );

        $by_tier = CW_Campaign_Showcase::partners_by_tier_with_placeholders( $campaign_id, $placeholders );
        if ( empty( $by_tier ) ) {
            return '';
        }

        $labels = CW_Campaign_Showcase::tier_labels();
        $label  = trim( (string) $atts['heading'] ) !== ''
            ? (string) $atts['heading']
            : ( (string) $atts['title'] !== '' ? (string) $atts['title'] : __( 'Partners', 'creativewings-core' ) );

        $champions = $by_tier['champion'] ?? [];
        $package_tiers = [ 'hero', 'community', 'local', 'inkind' ];
        $has_packages = false;
        foreach ( $package_tiers as $t ) {
            if ( ! empty( $by_tier[ $t ] ) ) {
                $has_packages = true;
                break;
            }
        }
        if ( empty( $champions ) && ! $has_packages ) {
            return '';
        }

        $speed = (string) max( 18, (int) $atts['speed'] );

        // Flatten non-champion packages for one marquee (order = package hierarchy).
        $package_logos = [];
        foreach ( $package_tiers as $t ) {
            $rows = $by_tier[ $t ] ?? [];
            if ( $t === 'inkind' && ! empty( $rows ) ) {
                $split = CW_Campaign_Showcase::split_by_segment( $rows );
                $rows  = array_merge( $split['media'] ?? [], $split['nonmedia'] ?? [] );
            }
            foreach ( $rows as $partner ) {
                $partner['_package'] = $t;
                $package_logos[]     = $partner;
            }
        }

        ob_start();
        ?>
        <section class="cw-home-partners cw-home-partners--tiered cw-home-partners--combined" data-cw-home-reveal aria-label="<?php echo esc_attr( $label ); ?>">
            <?php if ( trim( (string) $atts['heading'] ) !== '' ) : ?>
                <h2 class="cw-home-section-heading"><?php echo esc_html( $atts['heading'] ); ?></h2>
            <?php endif; ?>
            <?php if ( trim( (string) $atts['title'] ) !== '' ) : ?>
                <p class="cw-home-partners__title"><?php echo esc_html( $atts['title'] ); ?></p>
            <?php endif; ?>

            <?php if ( ! empty( $champions ) ) : ?>
            <div class="cw-home-partners__band cw-home-partners__band--champion">
                <p class="cw-home-partners__band-label"><?php echo esc_html( $labels['champion'] ); ?></p>
                <div class="cw-home-partners__static cw-home-partners__static--champion">
                    <?php foreach ( $champions as $partner ) :
                        $cell = CW_Campaign_Showcase::render_partner_cell_html( $partner, 'large' );
                        if ( $cell === '' ) {
                            continue;
                        }
                        ?>
                        <div class="cw-home-partners__static-item"><?php echo $cell; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ( ! empty( $package_logos ) ) : ?>
            <div class="cw-home-partners__band cw-home-partners__band--packages">
                <div class="cw-home-partners__viewport">
                    <div class="cw-home-partners__track" style="--cw-marquee-duration: <?php echo esc_attr( $speed ); ?>s">
                        <?php
                        $track = $package_logos;
                        if ( count( $track ) === 1 ) {
                            $track = array_merge( $track, $track );
                        }
                        $track = array_merge( $track, $track );
                        foreach ( $track as $partner ) :
                            $pkg  = sanitize_key( (string) ( $partner['_package'] ?? 'local' ) );
                            $size = ( $pkg === 'hero' || $pkg === 'community' ) ? 'large' : 'medium';
                            $cell = CW_Campaign_Showcase::render_partner_cell_html( $partner, $size );
                            if ( $cell === '' ) {
                                continue;
                            }
                            ?>
                            <div class="cw-home-partners__item cw-home-partners__item--<?php echo esc_attr( $pkg ); ?>" data-package="<?php echo esc_attr( $pkg ); ?>">
                                <?php echo $cell; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </section>
        <?php
        $this->enqueue_assets();
        return (string) ob_get_clean();
    }

    /**
     * In-kind band with Media + Non-media subgroups under one heading.
     *
     * @param string           $heading
     * @param array<int,array> $partners
     * @param string           $speed
     * @return string
     */
    private function render_home_inkind_band( $heading, array $partners, $speed ) {
        if ( empty( $partners ) || ! class_exists( 'CW_Campaign_Showcase' ) ) {
            return '';
        }
        $split  = CW_Campaign_Showcase::split_by_segment( $partners );
        $labels = CW_Campaign_Showcase::segment_labels();
        ob_start();
        ?>
        <div class="cw-home-partners__band cw-home-partners__band--marquee cw-home-partners__band--inkind">
            <p class="cw-home-partners__band-label"><?php echo esc_html( $heading ); ?></p>
            <?php foreach ( [ 'media', 'nonmedia' ] as $seg ) :
                $rows = $split[ $seg ] ?? [];
                if ( empty( $rows ) ) {
                    continue;
                }
                ?>
                <div class="cw-home-partners__subgroup cw-home-partners__subgroup--<?php echo esc_attr( $seg ); ?>">
                    <p class="cw-home-partners__subgroup-label"><?php echo esc_html( $labels[ $seg ] ); ?></p>
                    <?php echo $this->render_home_partner_marquee_track( $rows, $speed ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Marquee track markup for a list of partners.
     *
     * @param array<int,array> $partners
     * @param string           $speed
     * @return string
     */
    private function render_home_partner_marquee_track( array $partners, $speed ) {
        if ( empty( $partners ) || ! class_exists( 'CW_Campaign_Showcase' ) ) {
            return '';
        }
        ob_start();
        ?>
        <div class="cw-home-partners__viewport">
            <div class="cw-home-partners__track" style="--cw-marquee-duration: <?php echo esc_attr( $speed ); ?>s">
                <?php
                $track = $partners;
                if ( count( $track ) === 1 ) {
                    $track = array_merge( $track, $track );
                }
                $track = array_merge( $track, $track );
                foreach ( $track as $partner ) :
                    $cell = CW_Campaign_Showcase::render_partner_cell_html( $partner, 'medium' );
                    if ( $cell === '' ) {
                        continue;
                    }
                    ?>
                    <div class="cw-home-partners__item"><?php echo $cell; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * One labeled marquee band for a lower sponsor tier.
     *
     * @param string              $tier   Tier key.
     * @param string              $heading Band label.
     * @param array<int,array>    $partners Partner rows.
     * @param string              $speed  Marquee duration seconds.
     * @return string
     */
    private function render_home_partner_marquee_band( $tier, $heading, array $partners, $speed ) {
        if ( empty( $partners ) || ! class_exists( 'CW_Campaign_Showcase' ) ) {
            return '';
        }
        $tier = sanitize_key( (string) $tier );
        ob_start();
        ?>
        <div class="cw-home-partners__band cw-home-partners__band--marquee cw-home-partners__band--<?php echo esc_attr( $tier ); ?>">
            <p class="cw-home-partners__band-label"><?php echo esc_html( $heading ); ?></p>
            <?php echo $this->render_home_partner_marquee_track( $partners, $speed ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function enqueue_assets() {
        static $done = false;
        if ( $done ) {
            return;
        }
        $done = true;

        $js = class_exists( 'CW_Core_Platform' )
            ? CW_Core_Platform::asset( 'assets/js/cw-homepage-blocks.js' )
            : [ 'url' => CW_URL . 'assets/js/cw-homepage-blocks.js', 'version' => defined( 'CW_VERSION' ) ? CW_VERSION : null ];

        wp_enqueue_script(
            'cw-homepage-blocks',
            $js['url'],
            [],
            $js['version'] ?? ( defined( 'CW_VERSION' ) ? CW_VERSION : null ),
            true
        );
    }

    /**
     * Prefer soonest-closing open campaign; fall back to newest open campaign.
     */
    private function resolve_featured_campaign_id() {
        $today = current_time( 'Y-m-d' );
        $cache_key = 'featured:' . $today;

        if ( class_exists( 'CW_Cache' ) ) {
            return (int) CW_Cache::remember(
                $cache_key,
                'homepage_blocks',
                5 * MINUTE_IN_SECONDS,
                function () use ( $today ) {
                    return $this->resolve_featured_campaign_id_uncached( $today );
                }
            );
        }

        return $this->resolve_featured_campaign_id_uncached( $today );
    }

    private function resolve_featured_campaign_id_uncached( $today ) {
        $q = new WP_Query(
            [
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => 24,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => [
                    'relation' => 'OR',
                    [
                        'key'     => 'submission_deadline',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => 'submission_deadline',
                        'value'   => '',
                        'compare' => '=',
                    ],
                    [
                        'key'     => 'submission_deadline',
                        'value'   => $today,
                        'compare' => '>=',
                        'type'    => 'CHAR',
                    ],
                ],
            ]
        );

        $best_id = 0;
        $best_ts = PHP_INT_MAX;
        foreach ( $q->posts as $pid ) {
            $deadline = (string) get_post_meta( $pid, 'submission_deadline', true );
            if ( $deadline === '' ) {
                if ( ! $best_id ) {
                    $best_id = (int) $pid;
                }
                continue;
            }
            $ts = CW_Campaign_Dates::timestamp( $deadline, true );
            if ( ! $ts || $ts < time() ) {
                continue;
            }
            if ( $ts < $best_ts ) {
                $best_ts = $ts;
                $best_id = (int) $pid;
            }
        }

        return $best_id;
    }

    private function campaign_type_label( $pid ) {
        $main = CW_Shop::main_category( $pid );
        return $main['label'];
    }

    /**
     * @param string               $ids_csv Media attachment IDs.
     * @param string               $source  auto|manual|both
     * @param array<int,string>    $names   Optional names aligned with ids.
     * @return array<int,array{name:string,url:string}>
     */
    private function collect_partner_logos( $ids_csv, $source = 'auto', $names = [], $links = [] ) {
        $logos = [];
        $seen  = [];
        $strict = ( $source === 'manual' );
        $link_by_aid = $this->partner_link_map();

        $add = function ( $name, $attachment_id = 0, $url = '', $link = '' ) use ( &$logos, &$seen, $strict, $link_by_aid ) {
            $attachment_id = (int) $attachment_id;
            if ( $attachment_id > 0 ) {
                $path = get_attached_file( $attachment_id );
                if ( ! $path || ! file_exists( $path ) ) {
                    return;
                }
                $url = wp_get_attachment_url( $attachment_id );
                if ( ! $url ) {
                    return;
                }
                // Manual picks skip aspect filtering so curated logos always show.
                if ( ! $strict ) {
                    $size = @getimagesize( $path );
                    if ( is_array( $size ) && ! empty( $size[0] ) && ! empty( $size[1] ) ) {
                        $ratio = $size[0] / max( 1, $size[1] );
                        if ( $ratio > 3.2 || $ratio < 0.35 ) {
                            return;
                        }
                        if ( max( $size[0], $size[1] ) > 2200 ) {
                            return;
                        }
                    }
                }
                if ( $link === '' && isset( $link_by_aid[ $attachment_id ] ) ) {
                    $link = $link_by_aid[ $attachment_id ];
                }
            }

            $url = esc_url_raw( (string) $url );
            if ( $url === '' ) {
                return;
            }
            $link = esc_url_raw( (string) $link );

            $key = strtolower( basename( wp_parse_url( $url, PHP_URL_PATH ) ?: $url ) );
            $key = preg_replace( '/[^a-z0-9]+/', '', $key );
            // Collapse Creative Wings logo variants into one slot.
            if ( str_contains( $key, 'logocw' ) || str_contains( $key, 'croppedcwlogo' ) || str_contains( $key, 'cwlogo' ) ) {
                $key = 'creativewings-logo';
                if ( $name === '' || stripos( $name, 'creative' ) !== false ) {
                    $name = 'Creative Wings';
                }
            }
            if ( $key === '' || isset( $seen[ $key ] ) ) {
                return;
            }
            $seen[ $key ] = true;
            $logos[]      = [
                'name' => $name !== '' ? $name : __( 'Partner', 'creativewings-core' ),
                'url'  => $url,
                'link' => $link,
            ];
        };

        $ids = array_values( array_filter( array_map( 'absint', explode( ',', (string) $ids_csv ) ) ) );
        foreach ( $ids as $i => $aid ) {
            $label = isset( $names[ $i ] ) ? $names[ $i ] : html_entity_decode( get_the_title( $aid ), ENT_QUOTES, 'UTF-8' );
            $href  = isset( $links[ $i ] ) ? (string) $links[ $i ] : '';
            $add( $label, $aid, '', $href );
        }

        if ( $source === 'manual' ) {
            // Pad only for visual density; do not auto-scrape other logos.
            if ( count( $logos ) >= 2 && count( $logos ) < 6 ) {
                $base = $logos;
                while ( count( $logos ) < 6 ) {
                    foreach ( $base as $logo ) {
                        $logos[] = $logo;
                        if ( count( $logos ) >= 6 ) {
                            break;
                        }
                    }
                }
            }
            return $logos;
        }

        // Supporting partners from open campaigns (real partner logos).
        if ( class_exists( 'CW_Campaign_Showcase' ) ) {
            $today = current_time( 'Y-m-d' );
            $q     = new WP_Query(
                [
                    'post_type'      => 'product',
                    'post_status'    => 'publish',
                    'posts_per_page' => 20,
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                ]
            );
            foreach ( $q->posts as $pid ) {
                $deadline = (string) get_post_meta( $pid, 'submission_deadline', true );
                if ( $deadline !== '' && $deadline < $today ) {
                    continue;
                }
                foreach ( CW_Campaign_Showcase::get_partners( $pid ) as $row ) {
                    $add( $row['name'], (int) $row['attachment_id'], '', (string) ( $row['url'] ?? '' ) );
                }
            }
        }

        // Business logos — attachment ID only (skip broken remote URLs).
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT user_id, meta_value FROM {$wpdb->usermeta}
             WHERE meta_key = 'business_logo' AND meta_value <> ''
             LIMIT 40"
        );
        if ( $rows ) {
            foreach ( $rows as $row ) {
                $v   = maybe_unserialize( $row->meta_value );
                $aid = 0;
                if ( is_array( $v ) ) {
                    $aid = (int) ( $v['id'] ?? $v['ID'] ?? 0 );
                } elseif ( is_numeric( $v ) ) {
                    $aid = (int) $v;
                }
                if ( $aid <= 0 ) {
                    continue;
                }
                $name = get_user_meta( (int) $row->user_id, 'business_name', true );
                if ( ! $name ) {
                    $user = get_userdata( (int) $row->user_id );
                    $name = $user ? $user->display_name : '';
                }
                $add( (string) $name, $aid );
            }
        }

        // Curated fallback partners (verified attachments).
        if ( count( $logos ) < 3 ) {
            foreach ( [ 2566, 2565, 3877 ] as $aid ) {
                $add( html_entity_decode( get_the_title( $aid ), ENT_QUOTES, 'UTF-8' ), $aid );
            }
        }

        // Keep marquee dense even with few logos.
        if ( count( $logos ) >= 2 && count( $logos ) < 6 ) {
            $base = $logos;
            while ( count( $logos ) < 6 ) {
                foreach ( $base as $logo ) {
                    $logos[] = $logo;
                    if ( count( $logos ) >= 6 ) {
                        break;
                    }
                }
            }
        }

        return $logos;
    }

    /**
     * Map attachment ID → partner website from campaign showcase meta.
     *
     * @return array<int,string>
     */
    private function partner_link_map() {
        static $map = null;
        if ( is_array( $map ) ) {
            return $map;
        }
        $map = [];
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = 'cw_supporting_partners' AND meta_value <> ''
             LIMIT 200"
        );
        foreach ( (array) $rows as $row ) {
            $partners = maybe_unserialize( $row->meta_value );
            if ( ! is_array( $partners ) ) {
                continue;
            }
            foreach ( $partners as $partner ) {
                if ( ! is_array( $partner ) ) {
                    continue;
                }
                $aid  = (int) ( $partner['attachment_id'] ?? $partner['id'] ?? 0 );
                $href = esc_url_raw( (string) ( $partner['url'] ?? '' ) );
                if ( $aid > 0 && $href !== '' && empty( $map[ $aid ] ) ) {
                    $map[ $aid ] = $href;
                }
            }
        }
        return $map;
    }
}
