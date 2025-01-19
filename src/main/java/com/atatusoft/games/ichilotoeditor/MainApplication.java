package com.atatusoft.games.ichilotoeditor;

import javafx.application.Application;
import javafx.fxml.FXMLLoader;
import javafx.scene.Scene;
import javafx.stage.Stage;

public class MainApplication extends Application {
    static String gameName;
    static int width = 800;
    static int height = 600;

    @Override
    public void start(Stage stage) throws Exception {
        FXMLLoader fxmlLoader = new FXMLLoader(MainApplication.class.getResource("main-view.fxml"));
        Scene scene = new Scene(fxmlLoader.load(), width, height);
        stage.setTitle(String.format("%s - Ichiloto Editor", gameName));
        stage.setScene(scene);
        stage.show();
    }

    public static void main(String[] args) {
        for(int i = 0; i < args.length; i++) {
            switch (args[i]) {
                case "--name":
                case "-n":
                    if (i + 1 > args.length - 1) {
                        throw new RuntimeException(args[i] + " requires a value");
                    }

                    gameName = args[i + 1];
                    break;

                case "--width":
                case "-w":
                    if (i + 1 > args.length - 1) {
                        throw new RuntimeException(args[i] + " requires a value");
                    }

                    width = Integer.parseInt(args[i + 1]);
                    break;

                case "--height":
                case "-h":
                    if (i + 1 > args.length - 1) {
                        throw new RuntimeException(args[i] + " requires a value");
                    }

                    height = Integer.parseInt(args[i + 1]);
                    break;
            }
        }
        launch(args);
    }
}
