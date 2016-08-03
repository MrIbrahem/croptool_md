<?php

class CommonsPage {

    public $pagename;
    protected $cache = [];

    /**
     * CommonsPage constructor.
     * @param MwApiClient $apiClient
     * @param string $pagename
     * @param CommonsPage|null $derivedFrom
     */
    public function __construct(MwApiClient $apiClient, $pagename, CommonsPage $derivedFrom = null)
    {
        $this->api = $apiClient;
        $this->pagename = $pagename;
        $this->derivedFrom = $derivedFrom;
    }

    /**
     * @return LocalFile
     */
    public function getLocalFile()
    {
        return new LocalFile($this->getImageInfo());
    }

    /**
     * @return ImageInfo|null
     */
    public function getImageInfo()
    {
        if (!isset($this->cache['imageinfo'])) {
            if (!is_null($this->derivedFrom)) {
                $this->cache['imageinfo'] = $this->api->getImageInfo($this->derivedFrom->pagename);
            } else {
                $this->cache['imageinfo'] = $this->api->getImageInfo($this->pagename);
            }
        }
        return $this->cache['imageinfo'];
    }

    /**
     * @return WikiText
     */
    public function getWikiText()
    {
        if (!isset($this->cache['wikitext'])) {
            $response = $this->api->request(array(
                'action' => 'parse',
                'prop' => 'wikitext',
                'format' => 'json',
                'page' => 'File:' . $this->pagename
            ), false, false);

            $this->cache['wikitext'] = new WikiText($response->parse->wikitext->{'*'});
        }
        return $this->cache['wikitext'];
    }

    public function setWikiText(WikiText $wikitext)
    {
        $this->cache['wikitext'] = $wikitext;
    }

    /**
     * @return bool
     */
    public function exists()
    {
        return !is_null($this->getImageInfo());
    }

    /**
     * Returns true if the page is currently waiting for license review
     * @return bool
     */
    public function waitingForLicenseReview()
    {
        return $this->getWikiText()->waitingForLicenseReview();
    }

    /**
     * @return array
     */
    public function analyze()
    {
        $wikitext = $this->getWikiText();

        return array(
            'title' => $this->pagename,
            'text' => strval($wikitext),
            'elems' => $wikitext->possibleStuffToRemove(),
        );
    }

    /**
     * @param string $pagename
     * @return CommonsPage
     */
    public function makeDerivative($pagename)
    {
        if ($pagename == $this->pagename)
        {
            die('New pagename cannot be the same as the current pagename');
        }
        $newPage = new CommonsPage($this->api, $pagename, $this);

        // Clone original wikitext
        $wikitext = new WikiText($this->getWikiText());

        // Append the {{Extracted from}} template
        $wikitext->appendExtractedFromTemplate($this->pagename);

        // Remove templates that should not be copied, like license review templates
        $wikitext->removeTemplatesNotToBeCopied();

        $newPage->setWikiText($wikitext);

        return $newPage;
    }

    /**
     * @param $comment
     * @return array
     */
    protected function prepareUploadArgs($comment)
    {
        $path = $this->getPath();
        $token = $this->api->getEditToken();

        return array(
            'action' => 'upload',
            'format' => 'json',
            'filename' => $this->pagename,
            'token' => $token,
            'comment' => $comment,
            'file' => (version_compare(PHP_VERSION, '5.5.0') >= 0)
                ? new CURLFile($path)
                : '@' . $path
        );
    }

    /**
     * @return string
     */
    protected function getPath()
    {
        $imageInfo = $this->getImageInfo();
        $file = new LocalFile($imageInfo);
        return $file->getAbsolutePath('_cropped');
    }

    /**
     * @param string $comment
     * @param bool $ignoreWarnings
     * @return array
     */
    public function upload($comment, $ignoreWarnings=false)
    {
        $args = $this->prepareUploadArgs($comment);
        if (is_null($this->derivedFrom)) {

            // 'ignorewarnings' is needed to overwrite a file
            $args['ignorewarnings'] = '1';

        } else {

            if ($ignoreWarnings) {
                $args['ignorewarnings'] = '1';
            }

            $args['text'] = strval($this->getWikiText());
        }

        return $this->api->request($args, true);
    }

    /**
     * @param string $text
     * @param string $summary
     * @return array
     */
    public function update($text, $summary)
    {
        return $this->api->request(array(
            'action' => 'edit',
            'format' => 'json',
            'summary' => $summary,
            'token' => $this->api->getEditToken(),
            'title' => 'File:' . $this->pagename,
            'text' => $text
        ));
    }

    /**
     * @param $elems
     * @return bool
     */
    public function removeStuff($elems)
    {
        $wikitext = $this->getWikiText();
        $removed = $wikitext->removeStuff($elems);

        if (count($removed) != 0) {
            $this->update(strval($wikitext), 'Removed ' . (implode(' and ', $removed)) . ' using [[Commons:CropTool|CropTool]]');
            return true;
        }

        return false;
    }

}