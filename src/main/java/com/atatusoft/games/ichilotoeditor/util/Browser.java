package com.atatusoft.games.ichilotoeditor.util;

import java.io.File;


public class Browser {
    protected String directoryPath = "";
    protected File directory;

    public Browser(String directory) {
        this.directoryPath = directory;
        this.directory = new File(this.directoryPath);
    }

    public File[] listFiles() {
        return directory.listFiles();
    }
}
