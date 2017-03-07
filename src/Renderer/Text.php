<?php

class Kint_Renderer_Text extends Kint_Renderer
{
    public static $object_renderers = array(
        'blacklist' => 'Kint_Renderer_Text_Blacklist',
        'depth_limit' => 'Kint_Renderer_Text_DepthLimit',
        'nothing' => 'Kint_Renderer_Text_Nothing',
        'recursion' => 'Kint_Renderer_Text_Recursion',
        'trace' => 'Kint_Renderer_Text_Trace',
    );

    /**
     * The maximum length of a string before it is truncated.
     *
     * Falsey to disable
     *
     * @var int
     */
    public static $strlen_max = 0;

    /**
     * The default width of the terminal for headers.
     *
     * @var int
     */
    public static $default_width = 80;

    /**
     * Indentation width.
     *
     * @var int
     */
    public static $default_indent = 4;

    public $header_width = 80;
    public $indent_width = 4;

    private $plugin_objs = array();
    private $previous_caller;
    private $callee;
    private $show_minitrace = true;

    public function __construct(array $params = array())
    {
        parent::__construct($params);

        $params += array(
            'callee' => null,
            'caller' => null,
        );

        $this->callee = $params['callee'];
        $this->previous_caller = $params['caller'];
        $this->show_minitrace = !empty($params['settings']['display_called_from']);
        $this->header_width = self::$default_width;
        $this->indent_width = self::$default_indent;
    }

    public function render(Kint_Object $o)
    {
        if ($plugin = $this->getPlugin(self::$object_renderers, $o->hints)) {
            if (strlen($output = $plugin->render($o))) {
                return $output;
            }
        }

        $out = '';

        if ($o->depth == 0) {
            $out .= $this->colorTitle($this->renderTitle($o)).PHP_EOL;
        }

        $out .= $this->renderHeader($o);
        $out .= $this->renderChildren($o).PHP_EOL;

        return $out;
    }

    public function boxText($text, $width)
    {
        if (Kint_Object_Blob::strlen($text) > $width - 4) {
            $text = mb_substr($text, 0, $width - 7).'...';
        }

        $text .= str_repeat(' ', $width - 4 - Kint_Object_Blob::strlen($text));

        $out = '┌'.str_repeat('─', $width - 2).'┐'.PHP_EOL;
        $out .= '│ '.Kint_Object_Blob::escape($text).' │'.PHP_EOL;
        $out .= '└'.str_repeat('─', $width - 2).'┘';

        return $out;
    }

    public function renderTitle(Kint_Object $o)
    {
        if (($name = $o->getName()) === null) {
            $name = 'literal';
        }

        return $this->boxText($name, $this->header_width);
    }

    public function renderHeader(Kint_Object $o)
    {
        $output = array();

        if ($o->depth) {
            if (($s = $o->getModifiers()) !== null) {
                $output[] = $s;
            }

            if ($o->name !== null) {
                $output[] = Kint_Object_Blob::escape(var_export($o->name, true));

                if (($s = $o->getOperator()) !== null) {
                    $output[] = Kint_Object_Blob::escape($s);
                }
            }
        }

        if (($s = $o->getType()) !== null) {
            $output[] = $this->colorType(Kint_Object_Blob::escape($s));
        }

        if (($s = $o->getSize()) !== null) {
            $output[] = '('.Kint_Object_Blob::escape($s).')';
        }

        if (($s = $o->getValueShort()) !== null) {
            if (self::$strlen_max && Kint_Object_Blob::strlen($s) > self::$strlen_max) {
                $s = substr($s, 0, self::$strlen_max).'...';
            }
            $output[] = $this->colorValue(Kint_Object_Blob::escape($s));
        }

        return str_repeat(' ', $o->depth * $this->indent_width).implode(' ', $output);
    }

    public function renderChildren(Kint_Object $o)
    {
        if ($o->type === 'array') {
            $output = ' [';
        } elseif ($o->type === 'object') {
            $output = ' (';
        } else {
            return '';
        }

        $children = '';

        if ($o->value && is_array($o->value->contents)) {
            foreach ($o->value->contents as $child) {
                $children .= $this->render($child);
            }
        }

        if ($children) {
            $output .= PHP_EOL.$children;
            $output .= str_repeat(' ', $o->depth * $this->indent_width);
        }

        if ($o->type === 'array') {
            $output .= ']';
        } elseif ($o->type === 'object') {
            $output .= ')';
        }

        return $output;
    }

    public function colorValue($string)
    {
        return $string;
    }

    public function colorType($string)
    {
        return $string;
    }

    public function colorTitle($string)
    {
        return $string;
    }

    public function postRender()
    {
        $output = str_repeat('═', $this->header_width);

        if (!$this->show_minitrace) {
            return $this->colorTitle($output);
        } else {
            return $this->colorTitle($output.PHP_EOL.$this->calledFrom().PHP_EOL);
        }
    }

    protected function calledFrom()
    {
        $output = '';

        if (isset($this->callee['file'])) {
            $output .= 'Called from '.$this->ideLink($this->callee['file'], $this->callee['line']);
        }

        $caller = '';

        if (isset($this->previous_caller['class'])) {
            $caller .= $this->previous_caller['class'];
        }
        if (isset($this->previous_caller['type'])) {
            $caller .= $this->previous_caller['type'];
        }
        if (isset($this->previous_caller['function'])
            && !in_array($this->previous_caller['function'], array('include', 'include_once', 'require', 'require_once'))
        ) {
            $caller .= $this->previous_caller['function'].'()';
        }

        if ($caller) {
            $output .= ' ['.$caller.']';
        }

        return $output;
    }

    public function ideLink($file, $line)
    {
        return Kint_Object_Blob::escape(Kint::shortenPath($file)).':'.$line;
    }

    protected function getPlugin(array $plugins, array $hints)
    {
        if ($plugins = $this->matchPlugins($plugins, $hints)) {
            $plugin = end($plugins);

            if (!isset($this->plugin_objs[$plugin])) {
                $this->plugin_objs[$plugin] = new $plugin($this);
            }

            return $this->plugin_objs[$plugin];
        }
    }
}