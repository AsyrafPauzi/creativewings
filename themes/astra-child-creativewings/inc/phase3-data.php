<?php
/**
 * Phase 3 page content — Brand Story, Organization & Leadership, SDGs.
 * Sourced from live Elementor pages (IDs 2119, 3308, 2117).
 *
 * @package CW_Astra_Child
 */

defined( 'ABSPATH' ) || exit;

/**
 * Journey milestones for Brand Story.
 *
 * @return array<int,array{date:string,title:string,detail:string}>
 */
function cw_child_phase3_journey() {
	return [
		[
			'date'   => '~ 2008',
			'title'  => 'Yibon Creative / Yibon Mag Enterprise Founded',
			'detail' => 'Founded by Abner Yap',
		],
		[
			'date'   => '3 Jul 2026',
			'title'  => '“One Smile, One World” Flagship Initiative Launched',
			'detail' => 'Koperasi Guru-guru Malaysia @ Kingston Hotel 15, Ipoh',
		],
		[
			'date'   => '6 Jul 2026',
			'title'  => 'Creative Wings Sdn Bhd “CWSB” Incorporated',
			'detail' => 'Founded by Abner Yap',
		],
		[
			'date'   => '15 Jul 2026',
			'title'  => 'CWSB Mentors’ Network Introduced',
			'detail' => 'Sin Chew Daily, PJ',
		],
		[
			'date'   => '16 Sep 2026',
			'title'  => 'CWSB Community Engagement — Harmony Fest 2.0',
			'detail' => 'Dharma Drum Mountain Buddhist Center Malaysia, PJ',
		],
		[
			'date'   => '10 Oct 2026',
			'title'  => 'CWSB Community Engagement — World Mental Health Day 2026',
			'detail' => 'Everyone Has A Choice @ Kedai KL, Mahsa Avenue, KL',
		],
		[
			'date'   => '22 Oct 2026',
			'title'  => 'CWSB Mentors’ Online Sharing Session — “If There Were No Grades, How Would You Describe Your Child?”',
			'detail' => 'Beyond exam results, every child has unique qualities worth appreciating.',
		],
		[
			'date'   => '9 Nov 2026',
			'title'  => 'CWSB Community Engagement — Being OK with Not OK “不OK也OK”',
			'detail' => 'Discover why you don’t need to fix everything to Feel Okay @ Kuen Cheng High School, KL',
		],
	];
}

/**
 * Leadership people (founder + core team).
 *
 * @return array{founder:array,team:array<int,array>}
 */
function cw_child_phase3_leadership() {
	$u = 'https://creativewings.asia/wp-content/uploads';
	return [
		'founder' => [
			'name'  => 'Abner Yap',
			'role'  => 'Founding & Leadership',
			'focus' => 'Vision · Strategy · Learning Programs',
			'bio'   => 'Visionary leader overseeing ecosystem growth, partnerships, and strategic direction — integrating brand, education, and technology for long-term sustainability.',
			'image' => $u . '/2026/04/ChatGPT-Image-Apr-25-2026-06_24_17-PM.png',
		],
		'team'    => [
			[
				'name'  => 'Vion Pek',
				'role'  => 'Head of Creative, Design & Branding',
				'bio'   => 'Owns all creative design, branding, and visual campaigns that are central to Creative Wings’ identity and sponsor appeal.',
				'image' => $u . '/2026/04/WhatsApp-Image-2026-04-28-at-12.25.26.jpeg',
			],
			[
				'name'  => 'Asyraf',
				'role'  => 'Head of Platform & Technology',
				'bio'   => 'Building and maintaining the Creative Wings platform. Under him sit the Full-Stack Developer and UI/UX Designer, keeping the technical team cohesive.',
				'image' => $u . '/2026/04/WhatsApp-Image-2026-04-24-at-23.55.53.jpeg',
			],
			[
				'name'  => 'Sherene Chin',
				'role'  => 'Operations Manager of Events & Program',
				'bio'   => 'Organising, coordinating, and executing Creative Wings’ competition and event pipeline — community runs, school competitions, and the yearly program calendar. Also supports school outreach coordination.',
				'image' => $u . '/2026/04/WhatsApp-Image-2026-04-24-at-22.52.20.jpeg',
			],
			[
				'name'  => 'Amanda Yeo',
				'role'  => 'Head of Strategy & Ext. Affairs',
				'bio'   => 'Handles government relations, ESG partnerships, institutional sponsorships, and investor outreach — a door opener to corridors of power.',
				'image' => $u . '/2026/08/1-1.jpg',
			],
			[
				'name'   => 'Regine Yap · YW Chan',
				'role'   => 'Co-Managers of Finance & HR',
				'bio'    => 'Manage cash flow, budget discipline, KPI tracking, and making sure execution stays on track toward the 5-year milestones — taking operational oversight burden off the CEO’s plate.',
				'image'  => $u . '/2026/04/ChatGPT-Image-Apr-28-2026-06_15_17-PM.png',
				'image2' => $u . '/2026/04/photo1.jpg',
			],
		],
	];
}

/**
 * Mentors listed on Brand Story.
 *
 * @return array<int,array{name:string,bio:string,image:string}>
 */
function cw_child_phase3_mentors() {
	$u = 'https://creativewings.asia/wp-content/uploads';
	return [
		[
			'name'  => 'Abner Yap',
			'bio'   => '',
			'image' => $u . '/2026/08/6-1.jpg',
		],
		[
			'name'  => 'Amanda Yeo',
			'bio'   => 'Amanda Yeo is an ESG strategist and sustainability trainer with experience across government, research and professional services. As Founder & Managing Partner of Brighten Sphere Consultancy PLT, she helps organisations turn sustainability challenges into practical business opportunities. She is passionate about empowering businesses and communities to create meaningful, measurable impact.',
			'image' => $u . '/2026/10/amanda-yeo.jpg',
		],
		[
			'name'  => 'Kenji Heng',
			'bio'   => '',
			'image' => $u . '/2026/08/3-1.jpg',
		],
		[
			'name'  => 'Koh Su Yen',
			'bio'   => 'Su Yen is the founder of Emotion Pod, pioneering emotional literacy across Southeast Asia. Inspired by her journey through grief, she creates safe spaces and practical mental health tools. Her work has reached 1,000+ individuals through university and ASEAN collaborations. She also mentors founders on wellness, resilience and burnout prevention.',
			'image' => $u . '/2026/10/koh-su-yen.jpg',
		],
		[
			'name'  => 'Monamarie',
			'bio'   => 'Monamarie Lawrence is Co-Founder of Relationship Studio Malaysia since 2019, a Certified Enneagram Trainer & Coach, Accredited HRDC Trainer and HRDC-registered Training Provider. With over a decade in the training industry, she is also a Certified Leadership Coach. She helps individuals and organisations build stronger relationships, manage conflict and understand personality differences through trust, respect and effective communication.',
			'image' => $u . '/2026/10/monamarie-lawrence.jpg',
		],
		[
			'name'  => 'Priscilla',
			'bio'   => '',
			'image' => $u . '/2026/08/5-1.jpg',
		],
		[
			'name'  => 'Sebastian Soon',
			'bio'   => 'Sebastian Soon works as a project consultant at aZaaS, bridging the gap between clients and development teams on software projects from planning through to delivery. He came to technology after more than a decade in competitive swimming, training from the age of seven and representing Malaysia at two SEA Games.',
			'image' => $u . '/2026/10/sebastian-soon.jpg',
		],
		[
			'name'  => 'Winson',
			'bio'   => '',
			'image' => $u . '/2026/08/7-1.jpg',
		],
		[
			'name'  => 'Wong Si Peng',
			'bio'   => 'An avid scuba diver for over 20 years, Si Peng transitioned from corporate work to civil society at C4 Center in 2021. She manages multistakeholder advocacy for good governance, coordinates research on waste trade, promotes a global plastic treaty, and co-authored Ending Waste Colonialism, Governing Plastic Pollution.',
			'image' => $u . '/2026/10/wong-si-peng.jpg',
		],
	];
}

/**
 * Official SDG icon URLs (UN web icons in Media Library).
 *
 * @return array<int,array{n:int,url:string,label:string,blurb:string}>
 */
function cw_child_phase3_sdg_icons() {
	$base = 'https://creativewings.asia/wp-content/uploads/2025/12/E_WEB_';
	$goals = [
		1  => [
			'label' => 'No Poverty',
			'blurb' => 'End poverty in all its forms everywhere — and keep people from falling back into it.',
		],
		2  => [
			'label' => 'Zero Hunger',
			'blurb' => 'End hunger, achieve food security and improved nutrition, and promote sustainable agriculture.',
		],
		3  => [
			'label' => 'Good Health and Well-Being',
			'blurb' => 'Ensure healthy lives and promote well-being for all at all ages.',
		],
		4  => [
			'label' => 'Quality Education',
			'blurb' => 'Ensure inclusive and equitable quality education and promote lifelong learning opportunities for all.',
		],
		5  => [
			'label' => 'Gender Equality',
			'blurb' => 'Achieve gender equality and empower all women and girls.',
		],
		6  => [
			'label' => 'Clean Water and Sanitation',
			'blurb' => 'Ensure availability and sustainable management of water and sanitation for all.',
		],
		7  => [
			'label' => 'Affordable and Clean Energy',
			'blurb' => 'Ensure access to affordable, reliable, sustainable and modern energy for all.',
		],
		8  => [
			'label' => 'Decent Work and Economic Growth',
			'blurb' => 'Promote sustained, inclusive and sustainable economic growth, full and productive employment and decent work for all.',
		],
		9  => [
			'label' => 'Industry, Innovation and Infrastructure',
			'blurb' => 'Build resilient infrastructure, promote inclusive and sustainable industrialisation, and foster innovation.',
		],
		10 => [
			'label' => 'Reduced Inequalities',
			'blurb' => 'Reduce inequality within and among countries.',
		],
		11 => [
			'label' => 'Sustainable Cities and Communities',
			'blurb' => 'Make cities and human settlements inclusive, safe, resilient and sustainable.',
		],
		12 => [
			'label' => 'Responsible Consumption and Production',
			'blurb' => 'Ensure sustainable consumption and production patterns.',
		],
		13 => [
			'label' => 'Climate Action',
			'blurb' => 'Take urgent action to combat climate change and its impacts.',
		],
		14 => [
			'label' => 'Life Below Water',
			'blurb' => 'Conserve and sustainably use the oceans, seas and marine resources for sustainable development.',
		],
		15 => [
			'label' => 'Life on Land',
			'blurb' => 'Protect, restore and promote sustainable use of terrestrial ecosystems and halt biodiversity loss.',
		],
		16 => [
			'label' => 'Peace, Justice and Strong Institutions',
			'blurb' => 'Promote peaceful and inclusive societies, provide access to justice for all, and build effective institutions.',
		],
		17 => [
			'label' => 'Partnerships for the Goals',
			'blurb' => 'Strengthen the means of implementation and revitalise the global partnership for sustainable development.',
		],
	];
	$out = [];
	foreach ( $goals as $n => $goal ) {
		$out[] = [
			'n'     => $n,
			'url'   => $base . str_pad( (string) $n, 2, '0', STR_PAD_LEFT ) . '.png',
			'label' => $goal['label'],
			'blurb' => $goal['blurb'],
		];
	}
	return $out;
}

/**
 * Partners marquee shortcode used on Brand Story.
 */
function cw_child_phase3_partners_shortcode() {
	return '[cw_partners_marquee heading="Our Partners" ids="4014,4015,4021,4022,4023,4024,4025,4026,4027,4028,4029,4030,4031,4032,4033,4034,4790,4035,4036,4068,4069,4070,4071" names="Idaman Asal,ST & Partners,Yibon Creative,Malaysia Vegan Life,Shark Saver,Relationship Studio,Sinar Harian,Sin Chew Daily,Along With Love,Karang Kraf,Sim Lim,hearthwood,hanan,emotion pod,Brigthen,Sin Chew Daily — Vitality,Bentong 文冬 大大 LOOK,azaaz,MovaTree,Persatuan Guru Sekolah-Sekolah Cina Bentong,Kicap Bentong,IPEL,Cheong & Kimmy" links=",,https://yiboncreative.com/,https://www.facebook.com/m007.veganlife/,https://sharksavers.org.my/,https://relationshipstudio.sg/,http://www.sinarharian.com.my/,https://www.sinchew.com.my/,,https://www.karangkraf.com/,,,,https://emotionpod.com/,https://www.brightensphere.com/,,,,,,https://kicapbentong.com/,,"]';
}
