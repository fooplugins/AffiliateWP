<?php

class Affiliate_WP_Migrate_Affiliates_Pro extends Affiliate_WP_Migrate_Base {

	private $direct_affiliates;

	public function __construct() {
		$this->direct_affiliates = get_option( 'affwp_migrate_direct_affiliates', array() );
	}


	public function process( $step = 1, $part = '' ) {

		switch( $part ) {

			case 'affiliates' :

				$affiliates = $this->do_affiliates( $step );

				if( ! empty( $affiliates ) ) {

					$this->step_forward( $step, 'affiliates' );

				} else {

					// Proceed to the referrals part
					$redirect  = add_query_arg( array(
						'page'         => 'affiliate-wp-migrate',
						'type'         => 'affiliates-pro',
						'part'         => 'referrals',
						'step'         => 1
					), admin_url( 'index.php' ) );
					wp_redirect( $redirect ); exit;

				}

				break;

			case 'referrals' :

				$referrals = $this->do_referrals( $step );

				if( ! empty( $referrals ) ) {

					$this->step_forward( $step, 'referrals' );

				}

				break;

		}

		$this->finish();

	}

	public function step_forward( $step = 1, $part = '' ) {

		$step++;
		$redirect          = add_query_arg( array(
			'page'         => 'affiliate-wp-migrate',
			'type'         => 'affiliates-pro',
			'part'         => $part,
			'step'         => $step
		), admin_url( 'index.php' ) );
		wp_redirect( $redirect ); exit;

	}

	public function do_affiliates( $step = 1 ) {

		global $wpdb;
		$offset     = $step > 1 ? $step * 100 : 0;

		//only select affiliates that have recorded referrals or hits
		$affiliates = $wpdb->get_results( "SELECT wa.* FROM {$wpdb->prefix}aff_affiliates wa
LEFT OUTER JOIN {$wpdb->prefix}aff_referrals wf ON wf.affiliate_id = wa.affiliate_id
LEFT OUTER JOIN {$wpdb->prefix}aff_hits wh ON wh.affiliate_id = wa.affiliate_id
group by wa.affiliate_id,wa.name,wa.email,wa.from_date,wa.thru_date,wa.type,wa.status
HAVING count(wf.affiliate_id) > 0 OR count(wh.affiliate_id) > 0 LIMIT $offset, 100;" );

		$to_delete = array();

		if( $affiliates ) {
			foreach( $affiliates as $affiliate ) {

				$user_id = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}aff_affiliates_users WHERE affiliate_id = %d", $affiliate->affiliate_id ) );
				if( ! $user_id ) {

					$user    = get_user_by( 'email', $affiliate->email );
					$user_id = ! empty( $user->ID ) ? $user->ID : 0;

				}

				$rate = $wpdb->get_var( $wpdb->prepare( "SELECT attr_value FROM {$wpdb->prefix}aff_affiliates_attributes WHERE affiliate_id = %d AND attr_key = 'referral.rate'", $affiliate->affiliate_id ) );

				$earnings = $wpdb->get_var( $wpdb->prepare( "SELECT sum(amount) FROM {$wpdb->prefix}aff_referrals WHERE affiliate_id = %d", $affiliate->affiliate_id ) );

				$referrals = $wpdb->get_var( $wpdb->prepare( "SELECT count(affiliate_id) FROM {$wpdb->prefix}aff_referrals WHERE affiliate_id = %d", $affiliate->affiliate_id ) );

				$visits = $wpdb->get_var( $wpdb->prepare( "SELECT count(affiliate_id) FROM {$wpdb->prefix}aff_hits WHERE affiliate_id = %d", $affiliate->affiliate_id ) );

				$args = array(
					'status'          => $affiliate->status,
					'date_registered' => $affiliate->from_date,
					'user_id'         => $user_id,
					'payment_email'	  => $affiliate->email,
					'rate'            => $rate,
					'earnings'		  => $earnings,
					'referrals'		  => $referrals,
					'visits'		  => $visits
				);

				$id = affiliate_wp()->affiliates->add( $args );

				if( 'direct' == $affiliate->type ) {
					// We don't need direct affiliates, but we need to insert it in order to keep affiliate IDs correct
					$this->direct_affiliates[] = $affiliate->affiliate_id;
					update_option( 'affwp_migrate_direct_affiliates', $this->direct_affiliates );
					$to_delete[] = $id;
				}

			}

			if( ! empty( $to_delete ) ) {

				foreach( $to_delete as $aff_id ) {

					affiliate_wp()->affiliates->delete( $aff_id );

				}

			}

			return true;

		} else {

			// No affiliates found, so all done
			return false;

		}

	}

	public function do_referrals( $step = 1 ) {

		global $wpdb;
		$offset    = $step > 1 ? $step * 100 : 0;
		$referrals = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}aff_referrals ORDER BY referral_id LIMIT $offset, 100;" );

		if( $referrals ) {
			foreach( $referrals as $referral ) {

				if( in_array( $referral->affiliate_id, $this->direct_affiliates ) ) {
					continue; // Skip referrals for Direct
				}

				switch( $referral->status ) {

					case 'accepted' :
						$status = 'unpaid';
						break;
					case 'closed' :
						$status = 'paid';
						break;
					case 'rejected' :
						$status = 'rejected';
						break;
					case 'pending' :
					default :
						$status = 'pending';
						break;
				}

				$context = '';
				$data = maybe_unserialize( $referral->data );
				if( ! empty( $data ) ) {
					if( ! empty( $data['order_id'] ) ) {
						if( ! empty( $data['order_id']['domain'] ) ) {
							$context = $data['order_id']['domain'];
						}
					}
				}

				$args = array(
					'status'          => $status,
					'affiliate_id'    => $referral->affiliate_id,
					'date'            => $referral->datetime,
					'description'     => $referral->description,
					'amount'          => $referral->amount,
					'currency'        => strtoupper( $referral->currency_id ),
					'reference'       => $referral->reference,
					'context'         => $context,
					'visit_id'		  => 0,
					'custom'		  => ''
				);

				$id = affiliate_wp()->referrals->add( $args );

				if( 'paid' == $status ) {
					affwp_increase_affiliate_earnings( $referral->affiliate_id, $referral->amount );
					affwp_increase_affiliate_referral_count( $referral->affiliate_id );
				}
			}

			return true;

		} else {

			// No referrals found, so all done
			return false;

		}

	}

	public function finish() {
		delete_option( 'affwp_migrate_direct_affiliates' );
		wp_redirect( admin_url( 'admin.php?page=affiliate-wp' ) ); exit;
	}

}