<?php
namespace SICOR\SicAddress\ViewHelpers;

class OpenStreetMapViewHelper extends \TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper
{
    public function initializeArguments()
    {
        parent::initializeArguments(); // TODO: Change the autogenerated stub

        $this->registerArgument('id', 'integer', 'id of map', true);
        $this->registerArgument('markers', 'array', 'array of markers to show on map');
        $this->registerArgument('center', 'object', 'center marker object of map');
        $this->registerArgument('radius', 'float', 'given radius of radial search');
        $this->registerArgument('settings', 'array', 'map settings');
    }

    public function render()
    {
        $id = $this->arguments['id'];
        $markers = $this->arguments['markers'];
        $center = $this->arguments['center'];
        $radius = $this->arguments['radius'];
        $settings = $this->arguments['settings']['map'];
        $iconSettings = $this->arguments['settings']['icon'];
        $tileLayerUrl = $this->arguments['settings']['tileLayerUrl'];
        $tileLayerSettings = self::a2json($this->arguments['settings']['tileLayer']);
        $markerSettings = (array_key_exists('marker', $this->arguments['settings'])) ? $this->arguments['settings']['marker'] : [];

        if($center) {
            $settings['center'] = array($center->getLatitude(), $center->getLongitude());
        } else {
            $settings['center'] = array(0,0);
        }

        $mapObj = 'var sic_address_map_' . $id . ' = L.map("sic_address_map_' . $id . '", ' . self::a2json($settings) . ')';

        $markerObj = '';
        $icons = [];
        foreach($markers as $marker) {
            $markerLatLng = array($marker->getLatitude(), $marker->getLongitude());
            $categoryUid = 0;
            foreach($marker->getCategories() as $category) {
                $categoryUid = $category->getUid();
                if(empty($icons[$categoryUid])) {
                    // Try adding missing icon(s)
                    foreach($category->getSicAddressMarker() as $markerFile) {
                        $icons[$categoryUid] = '/fileadmin' . $markerFile->getOriginalResource()->getOriginalFile()->getIdentifier();
                    }
                }
                if(!empty($icons[$categoryUid])) break;
            }

            if(!empty($icons[$categoryUid])) {
                $markerSettings['icon'] = '.sic_address_icon_'. $id . '_' . $categoryUid;
            }

            $markerObj .= 'var sic_address_marker_' . $id . '_' . $marker->getUid() . ' = L.marker(' . self::a2json($markerLatLng) .
                ', ' .  self::a2json($markerSettings) .
                ').addTo(sic_address_map_' . $id .
                ').bindPopup(document.getElementById("sic_address_marker_popup_' . $id . '_' . $marker->getUid().'").innerHTML);' . "\n";
        }

        $iconObj = '';
        foreach($icons as $categoryUid=>$icon) {
            $iconSettings['iconUrl'] = $icon;
            $iconObj .= 'var sic_address_icon_' . $id . '_' . $categoryUid . ' = L.icon('.self::a2json($iconSettings).');';
        }

        $circleObj = '';
        if(\is_numeric($radius) && !empty($center)) {
            $circleObj = 'var circle = L.circle([' . $center->getLatitude() . ',' . $center->getLongitude() . '],';
            $circleSettings = array();

            $circleSettings['radius'] = $radius * 1000;
            $circleObj .= self::a2json($circleSettings);
            $circleObj .= ').addTo(sic_address_map_' . $id . ')';
        }

        $js = <<< JS
<script>
window.addEventListener("load", function(event) {
$mapObj;

L.tileLayer('$tileLayerUrl', $tileLayerSettings).addTo(sic_address_map_{$id});

$iconObj;
$markerObj;
$circleObj;

if(typeof circle != "undefined") {
    sic_address_map_$id.fitBounds(circle.getBounds());
}

})
</script>

JS;

        return str_replace(').', ")\n.", $js);
    }

    /**
     * convert array to json
     *
     * json_encode does not work here, because it converts object values to strings
     *
     * @param $arr
     * @param bool $isArray
     * @return string
     */
    public static function a2json($arr, $isArray = false) {
        $json = '';
        $count = 0;

        if(is_array($arr)) if(count($arr))
        foreach($arr as $key=>$val) {
            if($count++) {
                $json .= ', ';
            }

            if(!\is_numeric($key)) {
                $json .= "\n" . $key . ': ';
            } else {
                $isArray = true;
            }

            if(\is_string($val) && $val[0] !== '.' && $val[0] !== '[' && $val !== 'true' && $val !== 'false') {
                $value = '"' . \addslashes(trim($val)) . '"';
            } else {
                if(is_array($val)) {
                    $value = self::a2json($val, true);
                } else {
                    $value = trim($val);
                }
            }
            $json .= $value;
        }
        return $isArray ? '[' . $json . ']' : '{' . $json . '}';
    }
}
