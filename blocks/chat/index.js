( function ( wp ) {
	var el = wp.element.createElement;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var ServerSideRender = wp.serverSideRender;
	var __ = wp.i18n.__;

	registerBlockType( 'arwp/chat', {
		edit: function ( props ) {
			var agents = ( window.arwpBlockData && window.arwpBlockData.agents ) ? window.arwpBlockData.agents : [];
			var options = [ { label: __( 'Select an agent', 'arwp-agents' ), value: '0' } ].concat( agents );
			var agentId = props.attributes.agentId || 0;

			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'ARWP Chat', 'arwp-agents' ) },
						el( SelectControl, {
							label: __( 'Agent', 'arwp-agents' ),
							value: String( agentId ),
							options: options,
							onChange: function ( value ) {
								props.setAttributes( { agentId: parseInt( value, 10 ) || 0 } );
							}
						} )
					)
				),
				agentId
					? el( ServerSideRender, { block: 'arwp/chat', attributes: props.attributes } )
					: el( 'p', null, __( 'Choose an agent in the block sidebar.', 'arwp-agents' ) )
			);
		},
		save: function () {
			return null;
		}
	} );
}( window.wp ) );
